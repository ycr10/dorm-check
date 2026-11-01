# MySQL 初始化 1064：delayed 保留字修复

> 本文是单班旧版故障记录，所列旧版迁移命令不适用于年级版。年级版请先看[全年级升级说明](全年级升级说明.md)，不要直接执行下方旧命令。

适用错误：首次部署执行 CLI 初始化，在 `delayed INTEGER NOT NULL DEFAULT 0` 附近报 MySQL 1064。

## 原因和修复文件

这是程序的 SQL 兼容性问题，不是上传进度、Nginx、数据库密码或用户操作错误。`DELAYED` 是 MySQL 保留字，作为列名必须按标识符转义。本地已将 SQL 中的该列写为反引号包围的 `` `delayed` ``，保留原列名和接口字段，不需要改字段名或删除已有数据。

备份服务器上对应的源码文件到公开目录之外，再上传替换这两个文件：

| 本地文件 | 当前服务器对应位置 |
| --- | --- |
| `src/Schema.php` | `/www/wwwroot/dorm-check/src/Schema.php` |
| `src/Domain.php` | `/www/wwwroot/dorm-check/src/Domain.php` |

`Schema.php` 修复建表；`Domain.php` 同步修复两处查询及一处更新。不要只修建表而遗漏任务冻结时的更新语句。PHP 数组中的 `$night['delayed']` 不需要加反引号。

## 为什么重复 init 可能提示非空库

MySQL 建表通常不能像普通业务事务一样整体回滚。程序在建立 `night_tasks` 前已建立部分表；初始化失败后则会移除尚未完成的 `config/local.php`。

因此可能出现：**配置不存在，但库中留有部分空表**。此时再次 `init` 会触发非空库保护。不要为绕过检查删除所有表，也不要把这次修复理解为要改 MySQL 全局配置。

先暂停本站及它的 tick 计划任务，备份本项目数据库、私有配置和运行资料。以下命令在服务器终端执行，不在本地 Windows PowerShell 执行。PHP 的 `85` 路径必须替换成实际已经通过环境检查的版本；运行用户也以实际 FPM 用户为准。

## 情况 A：有效的 config/local.php 仍在

不要重建配置，不要生成新 `app_key`。确认配置指向本项目的正确数据库，上传两个修复文件后执行：

```bash
runuser -u www -- /www/server/php/85/bin/php \
  -d session.upload_progress.enabled=0 \
  -d session.serialize_handler=php \
  /www/wwwroot/dorm-check/bin/console.php migrate
```

当前 `migrate` 使用 `CREATE TABLE IF NOT EXISTS` 补齐缺失的表，不会清空已有表；也不能用它自动修复任意不匹配的表结构。目标库若不是本项目专用库，先停止核查。

接着执行下面的“收尾检查”。

## 情况 B：首次初始化在这次建表中失败，config/local.php 不存在

本分支仅适用于**从未成功投入使用、因上述 1064 留下部分初始表**的实例；其中 `settings` 表已由这次初始化建立。若之前已有真实账号和业务数据，优先恢复原私有配置和原 `app_key`，不要按这里生成新密钥。若你已经再次清成完全空库，应回到宝塔教程的正常 `init` 流程，而不是使用本分支。

### 1. 确认私有数据库密码文件

应已按宝塔教程建立：

```text
/www/wwwroot/dorm-check/config/db-password.php
```

它返回真实数据库密码，不能公开。如果尚未建立，按宝塔教程先创建，不要把密码写到命令参数里。

### 2. 只为这次未完成的新实例生成应用密钥

在受控服务器终端执行：

```bash
/www/server/php/85/bin/php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

私下保存输出，不要贴到聊天、截图或公开日志。此命令不涉及修改 PHP 配置。

### 3. 在 config/ 下补建私有 local.php

仅在该文件确实不存在时，通过宝塔文件编辑器新建：

```text
/www/wwwroot/dorm-check/config/local.php
```

内容如下；班级、数据库名、账号、密钥必须替换为真实值。数据库名和账号不能从示例猜测。

```php
<?php
declare(strict_types=1);

$config = require __DIR__ . '/example.php';
$config['env'] = 'production';
$config['app_url'] = 'https://dorm.example.com'; // 替换为实际站点地址
$config['class_name'] = '替换成你的班级名称';
$config['db_dsn'] = 'mysql:host=127.0.0.1;port=3306;dbname=替换成实际数据库名;charset=utf8mb4';
$config['db_user'] = '替换成实际数据库账号';
$config['db_password'] = require __DIR__ . '/db-password.php';
$config['app_key'] = '替换成刚才生成的完整base64密钥';
return $config;
```

保留项目原 `config/example.php`，不要把上述示例放在 `public/`。如果实际 MySQL 主机、端口不同，应与已经核实的连接信息保持一致。

设置权限并检查语法：

```bash
chown root:www /www/wwwroot/dorm-check/config/local.php
chmod 640 /www/wwwroot/dorm-check/config/local.php
/www/server/php/85/bin/php -l /www/wwwroot/dorm-check/config/local.php
```

同时确认本项目的 `storage/` 及已有运行文件归实际 FPM 用户读写，具体权限操作见宝塔教程。不要用 `777`，也不要对其他网站目录执行递归修改。

### 4. 用 migrate 补齐本次未完成的表

在当前终端清除可能遗留的外置配置路径，然后执行：

```bash
unset DORM_CONFIG

runuser -u www -- /www/server/php/85/bin/php \
  -d session.upload_progress.enabled=0 \
  -d session.serialize_handler=php \
  /www/wwwroot/dorm-check/bin/console.php migrate
```

本次错误发生前已经建立的 `settings` 表使版本读取可以执行；后续缺失表会由修复后的安装代码补齐。若提示 `settings` 表不存在，或出现另一种结构错误，停止核对当前库状态，不要反复删库、不要绕过版本保护。

成功预期为包含 `"ok":true`、`"schema_version":1` 的结果。这里是预期说明，不是本轮已经在你的 MySQL 上执行的记录。

## 收尾检查

```bash
runuser -u www -- /www/server/php/85/bin/php \
  -d session.upload_progress.enabled=0 \
  -d session.serialize_handler=php \
  /www/wwwroot/dorm-check/bin/console.php doctor

runuser -u www -- /www/server/php/85/bin/php \
  -d session.upload_progress.enabled=0 \
  -d session.serialize_handler=php \
  /www/wwwroot/dorm-check/bin/console.php tick
```

无未解决错误后，如果尚未创建管理员，再按宝塔教程执行 `user:create`，实际激活、绑定 TOTP 并登录验收。不要重复创建同一学号。检查 Nginx 的实际 Web 请求也使用修复后的代码；共享 PHP 不要为了更新代码无计划重启，OPcache 更新方式按宝塔教程的多站点说明处理。

完成现场检查后，再恢复本站及它自己的计划任务。全程不需要改其他网站的 PHP 配置，也不需要删除数据库。

## 本次本地验证边界

执行：`node work/php-wasm/check.mjs test`。

结果：13 个 PHP 文件语法解析通过；43 项通过、0 失败。新增测试捕获 MySQL 分支生成的建表语句，检查保留字已加反引号；其余回归在隔离 SQLite 上执行。该捕获测试不连接 MySQL，也不能代替 MySQL 的真实建表和业务验收。WASM 缺少 Argon2id，成功密码认证链仍明确跳过。
