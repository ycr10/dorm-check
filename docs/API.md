# API 契约（首版）

同域入口：`/api/index.php?action=动作名`。本文描述实际源码接口，不代表生产环境已部署。单实例对应一个班级；无跨班级切换参数。

## 1. 请求与响应

- 读请求 GET；其他动作 POST，`Content-Type: application/json`，顶层必须是对象。
- POST 带 `X-CSRF-Token`，Origin／Referer 必须与配置的 `app_url` 同源。先 GET `session` 取得 CSRF；使用服务端 Session Cookie，不使用客户端角色或 bearer 调试令牌。
- 成功：`{"ok":true,"data":{},"csrf":"..."}`。
- 失败：`{"ok":false,"error":{"message":"...","code":"..."},"csrf":"..."}`。内部故障只返回错误编号，不返回栈。
- JSON 请求最大 3 MiB。GET 参数必须标量；动作允许列表固定，`.` 会归一化成 `_`，建议统一用下划线。
- `X-Passive-Request: 1` 用于业务轮询，不延长闲置会话。401 清除客户端私有状态；403 只处理权限／验证提示，不一律退出。
- 时间采用北京时间 `YYYY-MM-DD HH:mm:ss`，日期 `YYYY-MM-DD`。人工发生时间与服务端接收时间分开；HTTP 不接受测试时钟。

所有业务接口要求有效账号；当前策略要求绑定但尚未绑定 TOTP 时返回 `MFA_SETUP_REQUIRED`。尚未配置 `totp_student_no` 时沿用 admin／committee 强制规则；配置后仅指定学号强制绑定，其他账号不再启用两步验证。权限以数据库当前状态为准，不采信请求中的 role、actor_id 或负责人快照。

## 2. 身份与会话

| 动作 | 方法 | 主要参数／结果 |
| --- | --- | --- |
| `session` | GET | 匿名也可；返回 user（可为 null）、server_time、config；顶层 csrf |
| `activate` / `reset_password` | POST | student_no、code、password；设置后另行登录 |
| `login` | POST | student_no、password，可选 otp；可能返回 needs_totp 或 must_reenroll |
| `totp_setup` | POST | 已登录且当前策略允许、尚未绑定；返回 secret、otpauth_url，仅用于本人绑定 |
| `totp_confirm` | POST | code；在写事务内重新核对策略及会话版本，返回一次性展示的 recovery_codes |
| `reauth` | POST | password；已启用 TOTP 的账号另需新的 otp；更新近期验证窗口 |
| `change_password` | POST | current_password、password；已启用 TOTP 的账号须近期验证；完成后重新登录 |
| `logout` / `logout_all` | POST | 注销当前／全部会话 |
| `user_create` | POST | admin＋近期验证；student_no、name、role |
| `user_reset` | POST | admin＋近期验证；user_id；返回新重置码 |
| `user_status` | POST | admin＋近期验证；user_id、status=active/disabled；不能停用自己 |
| `users_list` | POST | admin＋近期验证；无参数，按当前入口约定不是 GET |

密码按 Unicode 字符计数为 15–64 字符，使用原生 Argon2id；本轮 WASM 不支持该算法，成功密码认证未验证。恢复码作为 login 的 otp 输入；使用后原验证器及其余恢复码失效，当前策略指定的账号必须重新绑定。用户对象增加 `totp_allowed`，不允许的账号不能调用绑定接口，前端隐藏绑定入口；后端返回 `MFA_NOT_ALLOWED`，不是只隐藏按钮。

## 3. 任务和历史

- `tasks` GET：可选 date。返回所选日期 `tasks`，另外返回较早且未结项的当前授权 `open_tasks`，以及 server_time、check_time、reminder_time。
- 每个寝室任务的 `id` 为字符串 `夜任务ID.寝室ID`，如 `1.2`；数字 `task_id` 字段仅表示夜任务本身，**变更请求须使用完整字符串作为 task_id**。
- 任务带 business_date、check_at、due_at、phase、roster_version、report_version、submitted_at、late、roster_empty、members。
- `task_history` GET：task_id；返回 task 和完整 events。转入成员可见本人同晚之前的事件，但不因此获得原寝其他人的记录。
- `dashboard` GET：manager，可选 date；返回汇总、人员、当晚任务、事件和调度运行结果。

GET 不建立任务、不冻结名单、不保存日报。CLI `tick` 负责调度；正式提交会补做必要冻结，并重新检查版本。设备时间不能提前开放任务。

## 4. 初报、补报与更正

三个动作均要求：task_id、`version`（当前 report_version）、12–100 字符的 idempotency_key。键仅允许字母、数字、`.`、`_`、`:`、`-`。同人同动作同键同内容返回原结果；同键不同内容 409。撤权后即使同键重放也拒绝。

### report_initial

另需 roster_version 和完整 members 数组，不能漏人、重复或夹带其他寝室成员：

```json
{
  "task_id": "1.2",
  "version": 0,
  "roster_version": 2,
  "idempotency_key": "example-request-0001",
  "members": [
    {
      "student_id": 12,
      "status": "away",
      "location": "图书馆",
      "current_status": "present",
      "current_location": "",
      "occurred_at": "2026-10-29 22:45:00"
    }
  ]
}
```

上例仅示意一人，实际必须发送该任务完整成员数组。每人字段：

- status：查寝时点的 present／away／exempt／unknown；不能主动选择 unreported。
- location：查寝时点去向。away／unknown 时必填，exempt 须有服务端有效核实安排。
- current_status：可选，省略表示与初报一致；不要用空字符串代表省略。
- current_location：当前去向。省略且当前状态与初报相同则沿用初报去向；状态不同则不自动沿用旧地点。当前 away／unknown 必须有当前已知情况。
- expected_return_at：可选预计返寝时间；occurred_at：可选实际变化时间，必须在 check_at 与服务器当前时点之间。

原始与当前去向独立保存。已存在初报的转入成员必须保留其原始 status/location，不通过目标寝室初报覆盖。任何成员验证失败，整批回滚。

### report_update

另需 student_ids（完整本批选择，无重复）、status、location，可选 occurred_at、reason。必须先初报；只更新所选成员当前状态，保留原始初报。

### report_correct

另需 student_id、target=current/initial、status、location、必填 reason。leader 只能更正 current；manager 可更正 initial。更正初报保存 corrected_initial_*，不会覆盖 initial_*；初报后补列且原始为 unreported 的成员，班委补正时同步补齐当前投影。

成功返回 `data.task`。主要 409：TASK_NOT_OPEN、TASK_CLOSED、VERSION_CONFLICT、ROSTER_CONFLICT、IDEMPOTENCY_CONFLICT、ALREADY_SUBMITTED、INITIAL_IMMUTABLE。冲突后读取最新任务、重新人工核实，用新键提交修改后的内容。

## 5. 草稿、范围与结项

| 动作 | 方法 | 参数与边界 |
| --- | --- | --- |
| `draft_get` | GET | task_id；仅当前账号自己的草稿，返回 draft/null、stale |
| `draft_save` | POST | task_id、roster_version、members；允许尚未填完的状态，保存 location/current_location 和时间；不计初报 |
| `task_roster_adjust` | POST | manager；task_id、roster_version、完整 student_ids、reason；仅已冻结且开放任务 |
| `task_close` / `task_reopen` | POST | manager；task_id、reason；有未解决人员或缺报不能正常结项 |

调整只改本晚，保留移出成员及历史事件，不修改当前日常住宿；跨寝先移出再加入，不能一人同时纳入两个寝室。空范围自动关闭且不计缺报。旧晚交接须指定完整任务，不能回溯一般授权获得所有历史任务。

## 6. 名册、授权与安排

均要求 manager；账号维护另按第 2 节 admin 限制。

- `roster` / `grants` GET：返回当前学生、寝室、必要账号、授权和安排。
- `import_preview` POST：csv；返回 errors、warnings、hash。UTF-8，可 BOM，最大 2 MiB、1,000 条；学号保持字符串。
- `import_commit` POST：相同 csv、hash、confirm=true。hash 绑定原文、当前操作者及班级；仅新增／更新，不隐式删除未出现者。
- `student_retire` POST：student_id、reason；移出后续名册，不删除冻结历史，也不自动停用账号／撤销授权。仍被其他有效学生引用为负责人的，须先交接。
- `grant_save` POST：近期验证；user_id（leader 账号）、room_id、valid_from，可选 valid_to、task_id（完整任务字符串）。日期值可用于整日授权，时间值用于精确范围。
- `grant_revoke` POST：近期验证；grant_id。
- `exemption_save` POST：student_id、start_date、end_date、source、note、approved=true；approved 不是对 leader 的提权开关。
- `exemption_revoke` POST：id、reason；开放任务中受影响且仍为 exempt 的人员转 unknown，原始初报及旧日报不变。

## 7. 跟进、日报、设置和审计

- `followups` / `inspections` GET：manager，date。
- `followup_save` POST：task_id、student_id、state、note。state=pending/contacted/safe/waiting/closed。safe 不改人员在寝状态。
- `inspection_save` POST：task_id、result、note；记录核实结果，不自动改申报。
- `reports` GET：manager，date，version=latest 或固定日报记录 id；返回的 version 是该晚显示用版本号，不是查询用 id。
- `report_snapshot` POST：manager，date、reason；保存不可变版本。
- `export` POST：manager＋近期验证，date、version；**返回 XLSX 二进制而非 JSON**，六表来自同一快照。私有磁盘路径不会返回浏览器，读取完删除临时文件。
- `settings` GET：manager 可读取规则与运行结果；`settings_save` POST：仅 admin＋近期验证，以 `{"settings":{...}}` 对象提交 class_name、check_time、due_time、reminder_time、paused_dates、retention_days、totp_student_no。冻结任务时间不被新配置改写；retention_days 不触发自动删除。修改两步验证账号另须下面的显式确认参数。
- `audit` GET：仅 admin，page（从 1 开始，每页 50 条）；返回 items、page，含对象详情但不含凭据原文。

### 指定唯一两步验证账号

`settings.totp_student_no` 为 null 时兼容旧规则（只读兼容状态，不接受把 null 写入作为关闭操作）。保存后为唯一账号的完整学号字符串，必须已激活且未停用，不能留空或传数字。

首次设置请求示例，学号为虚构示意：

```json
{
  "settings": {"totp_student_no": "00990000"},
  "totp_previous_student_no": null,
  "confirm_totp_change": true
}
```

后续更改时 `totp_previous_student_no` 必须与当前设置严格一致，否则 409 `MFA_POLICY_CONFLICT`；缺少布尔 true 的确认参数则 409 `MFA_POLICY_CONFIRM_REQUIRED`。只保存其他设置不需要这些字段。

策略变更在同一写事务中保留目标的绑定和恢复码，清除其他账号的 TOTP 密钥／绑定／恢复码，并增加所有账号的 `auth_version`，使全部旧会话失效。成功响应 `relogin_required=true`，前端重新读取 session 并回到登录页。相同目标再次保存或仅保存日常规则返回 false，不重复清理。角色、寝室授权、密码均不改变。

绑定确认和恢复码使用与策略写入使用相同的串行化写锁，重新检查当前策略／账号版本，不能用旧的待绑定 Session 在策略变更后重新启用其他账号。

维护标记期间业务接口返回 503；普通维护仍允许登录、密码恢复和验证器设置。恢复中全阻断标记连身份接口也暂停。备份、恢复、结构迁移和保留删除均为 CLI 操作，不提供 HTTP 管理后门。
