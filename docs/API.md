# API 契约（年级版）

同域入口：`/api/index.php?action=动作名`。本文描述实际源码接口，不代表生产环境已部署。单实例管理多个班级；后台以当前账号的班级授权控制数据范围。

## 1. 请求与响应

- 读请求 GET；其他动作 POST，`Content-Type: application/json`，顶层必须是对象。
- POST 带 `X-CSRF-Token`，Origin／Referer 必须与配置的 `app_url` 同源。先 GET `session` 取得 CSRF；使用服务端 Session Cookie，不使用客户端角色或 bearer 调试令牌。
- 成功：`{"ok":true,"data":{},"csrf":"..."}`。
- 失败：`{"ok":false,"error":{"message":"...","code":"..."},"csrf":"..."}`。内部故障只返回错误编号，不返回栈。
- JSON 请求最大 3 MiB。GET 参数必须标量；动作允许列表固定，`.` 会归一化成 `_`，建议统一用下划线。
- `X-Passive-Request: 1` 用于业务轮询，不延长闲置会话。401 清除客户端私有状态；403 只处理权限／验证提示，不一律退出。
- 时间采用北京时间 `YYYY-MM-DD HH:mm:ss`，日期 `YYYY-MM-DD`。人工发生时间与服务端接收时间分开；HTTP 不接受测试时钟。

除下述 `quick_*` 便捷入口及只读的 `public_settings` 外，业务接口要求有效账号；当前策略要求绑定但尚未绑定 TOTP 时返回 `MFA_SETUP_REQUIRED`。尚未配置 `totp_student_no` 时沿用 admin／committee 强制规则；配置后仅指定学号强制绑定，其他账号不再启用两步验证。权限以数据库当前状态为准，不采信请求中的 role、actor_id 或负责人快照。

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
| `user_create` | POST | admin＋近期验证；student_no、name、role=admin/committee；不能创建宿舍长角色 |
| `user_reset` | POST | admin＋近期验证；user_id；返回新重置码 |
| `user_status` | POST | admin＋近期验证；user_id、status=active/disabled；不能停用自己 |
| `users_list` | POST | admin＋近期验证；无参数，按当前入口约定不是 GET |

密码按 Unicode 字符计数为 15–64 字符，使用原生 Argon2id；本轮 WASM 不支持该算法，成功密码认证未验证。恢复码作为 login 的 otp 输入；使用后原验证器及其余恢复码失效，当前策略指定的账号必须重新绑定。用户对象增加 `totp_allowed`，不允许的账号不能调用绑定接口，前端隐藏绑定入口；后端返回 `MFA_NOT_ALLOWED`，不是只隐藏按钮。

## 2.1 公开备案信息

`public_settings` GET 无需登录、无需 Session，仅返回 `filing_icp` 与 `filing_police` 字符串（默认均为空）。它在维护期间仍可读取；不返回其他设置。管理员经近期身份验证，通过 `settings_save` 设置这两项；填写公安备案号时须符合“省份简称＋公网安备＋数字＋号”的格式。页面只以文本节点显示，并从数字构造固定域名的查询链接；运营者仍需自行核验备案平台信息。设置为空或接口不可用时，不显示相应内容。

## 3. 免登录寝室便捷登记

`quick_lookup`、`quick_initial`、`quick_update` 均为 POST JSON，仍要求同源、CSRF 和维护模式检查，但**不要求用户登录，不证明实际填报人的身份**。三者不会建立登录态、无法用于其他需鉴权的 API。普通真实客户端 IP 的查询和提交各限每 5 分钟 60 次；若共用代理出口，限额也可能被共用。

- 三个动作均传 `student_no` 和 `room_number` 字符串；按当前有效宿舍负责人及同寝关系匹配当晚物理宿舍。未匹配或不唯一返回统一错误，不支持指定其他任务 ID、日期、角色或测试时钟。跨班混住时返回该寝所有班级成员的姓名、班级、记录状态和版本，不返回其他宿舍或历史事件。
- 00:00–07:59:59 使用前一晚，08:00 以后使用当天；前一晚不存在时不会临时伪造历史任务。今天可补建必要任务，提交仍检查开放时间与冻结名册。
- `quick_initial` 另传 version、roster_version、idempotency_key 及完整 members 数组（字段与 `report_initial` 相同）；`quick_update` 另传 version、student_ids、status、location 和 idempotency_key。仅允许当前寝室人员、仅允许更新当前状态；更正原始记录及旧晚交接仍走登录流程。
- 不信任客户端声明的身份。事件 actor_id=null、actor_name=“便捷入口（未验证身份）”，审计记录填写的学号与 `identity_verified=false`；知道学号和房号的人可能冒用，限流／CSRF 不解决这一风险。
- 记住学号和房号的 Cookie 只由 `quick.html` 在用户选择时设置，30 天，SameSite=Strict、HTTPS 时 Secure、路径限定为该页面；不存密码、名单或申报数据，可随时清除。Cookie 为浏览器可读取的普通偏好，不是登录或身份凭据。

## 4. 任务和历史

- `tasks` GET：可选 date、class_id。只返回所选日期、当前班级授权范围的 `tasks`；管理员省略 class_id 可看全年级，班委省略时看其全部授权班级。兼容字段 `open_tasks` 恒为空，不再自动混入之前未结项任务。未指定 date 时，00:00–07:59:59 默认前一晚，08:00 起默认当天。返回 server_time、check_time、reminder_time。
- 每个寝室任务的 `id` 为字符串 `夜任务ID.寝室ID`，如 `1.2`；数字 `task_id` 字段仅表示夜任务本身，**变更请求须使用完整字符串作为 task_id**。
- 任务带 business_date、check_at、due_at、phase、roster_version、report_version、submitted_at、late、roster_empty、members。
- `task_history` GET：task_id；返回 task 和完整 events。转入成员可见本人同晚之前的事件，但不因此获得原寝其他人的记录。
- `dashboard` GET：admin/committee，可选 date、class_id；返回授权范围内重新计算的汇总、人员、当晚任务、事件和调度运行结果。请求不属于授权范围的 class_id 返回 `FORBIDDEN`。

GET 不建立任务、不冻结名单、不保存日报。已登录用户浏览默认日期的相关页面时，客户端会先 POST `prepare_today`（无参数，服务器决定业务日期）：08:00 起可以补建当天任务；08:00 前若前一晚任务已存在则可补准备和冻结，不存在则不伪造历史。它不保存自动日报，也不回溯补建历史。CLI `tick` 仍按自然日负责持续调度及日报；正式提交会重新检查版本。设备时间不能提前开放任务。

## 5. 初报、补报与更正

三个动作均要求：task_id、`version`（当前 report_version）、12–100 字符的 idempotency_key。键仅允许字母、数字、`.`、`_`、`:`、`-`。同人同动作同键同内容、授权范围不变时返回原结果；同键不同内容 409。撤权后不允许利用缓存越权，若仍可访问该任务但权限范围已变化，重试返回 409 `SCOPE_CHANGED`，应重新读取当前结果。旧版缓存不自动越权重放。

### report_initial

另需 roster_version 和当前班级范围内的完整 members 数组，不能漏人、重复或夹带无权成员；管理员未限定班级时需整寝成员。混住宿舍班委初报只影响获授权人员；整寝全部应报成员完成后才标记整寝完成：

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

上例仅示意一人，实际必须发送该任务在本次授权范围内的完整成员数组。每人字段：

- status：查寝时点的 present／away／exempt／unknown；不能主动选择 unreported。
- location：查寝时点去向。away／unknown 时必填，exempt 须有服务端有效核实安排。
- current_status：可选，省略表示与初报一致；不要用空字符串代表省略。
- current_location：当前去向。省略且当前状态与初报相同则沿用初报去向；状态不同则不自动沿用旧地点。当前 away／unknown 必须有当前已知情况。
- expected_return_at：可选预计返寝时间；occurred_at：可选实际变化时间，必须在 check_at 与服务器当前时点之间。

原始与当前去向独立保存。已存在初报的转入成员必须保留其原始 status/location，不通过目标寝室初报覆盖。任何成员验证失败，整批回滚。

### report_update

另需 student_ids（完整本批选择，无重复）、status、location，可选 occurred_at、reason。必须先初报；只更新所选成员当前状态，保留原始初报。

### report_correct

另需 student_id、target=current/initial、status、location、必填 reason。admin/committee 在其班级授权范围内可更正 initial。更正初报保存 corrected_initial_*，不会覆盖 initial_*；初报后补列且原始为 unreported 的成员，班委补正时同步补齐当前投影。

成功返回 `data.task`。主要 409：TASK_NOT_OPEN、TASK_CLOSED、VERSION_CONFLICT、ROSTER_CONFLICT、IDEMPOTENCY_CONFLICT、SCOPE_CHANGED、ALREADY_SUBMITTED、INITIAL_IMMUTABLE。冲突后读取最新任务、重新人工核实，用新键提交修改后的内容。

## 6. 草稿、范围与结项

| 动作 | 方法 | 参数与边界 |
| --- | --- | --- |
| `draft_get` | GET | task_id；仅当前账号自己的草稿，返回 draft/null、stale |
| `draft_save` | POST | task_id、roster_version、members；允许尚未填完的状态，保存 location/current_location 和时间；不计初报 |
| `task_roster_adjust` | POST | manager；task_id、roster_version、完整 student_ids、reason；仅已冻结且开放任务 |
| `task_close` / `task_reopen` | POST | manager；task_id、reason；有未解决人员或缺报不能正常结项 |

调整只改本晚，保留移出成员及历史事件，不修改当前日常住宿；跨寝先移出再加入，不能一人同时纳入两个寝室。空范围自动关闭且不计缺报。结项、重新开放、整寝名册调整和抽查写入必须对该寝全部成员的班级有权限，或由管理员在全年级范围执行。历史日期按当前班级授权查询，历史成员按该晚冻结的班级归属过滤。

## 7. 名册、授权与安排

均要求 admin/committee；账号和班级管理另要求 admin＋近期验证。所有目标学生、班级、历史记录在服务端重新核对授权。

- `classes` GET：列出当前账号可用班级；admin 可见全年级。
- `class_create` POST：admin＋近期验证，name；`class_rename` POST：admin＋近期验证，class_id、name；保留历史名称映射和冻结快照。
- `class_permissions_save` POST：admin＋近期验证，user_id、class_ids 数组；设置班委可访问的班级，空数组表示撤销全部。目标账号现有会话版本失效。
- `room_leader_change` POST：admin＋近期验证，room_id、student_no、reason；新负责人必须是该寝有效成员，更新当前住宿关系，不改冻结历史。
- `roster` GET：可选 class_id；返回授权范围内当前学生、相关宿舍和安排，账号及班级授权列表仅管理员可见。
- `import_preview` POST：class_id、csv；返回 errors、warnings、changes、hash 及预览行。UTF-8，可 BOM，最大 2 MiB、1,000 条；学号保持字符串。缺失或不在本寝的负责人会产生警告，核实之前不能通过便捷入口填报。
- `import_commit` POST：相同 class_id、csv、hash、confirm=true。hash 绑定原文、操作者、目标班级与当前名单状态；仅新增／更新，不隐式删除未出现者。跨班转入须对来源和目标均有权限；共享寝室负责人不一致时拒绝覆盖。
- `student_retire` POST：student_id、reason；移出后续名册，不删除冻结历史，也不自动停用账号／撤销授权。仍被其他有效学生引用为负责人的，须先交接。
- `exemption_save` POST：student_id、start_date、end_date、source、note、approved=true；学生须在当前班级范围内。
- `exemption_revoke` POST：id、reason；须覆盖受影响的历史班级；开放任务中受影响且仍为 exempt 的人员转 unknown，原始初报及旧日报不变。

不再提供 `grant_save`、`grant_revoke` 或宿舍长账号授权接口。

## 8. 跟进、日报、设置和审计

- `followups` / `inspections` GET：manager，date。
- `followup_save` POST：task_id、student_id、state、note。state=pending/contacted/safe/waiting/closed。safe 不改人员在寝状态。
- `inspection_save` POST：task_id、result、note；记录核实结果，不自动改申报。
- `reports` GET：admin/committee，date、可选 class_id，version=latest 或固定日报记录 id；固定快照在返回时按当前班级权限及历史班级归属投影，重新计算人数和汇总，未知归属仅管理员可见。返回的 version 是该晚显示用版本号，不是查询用 id。
- `report_snapshot` POST：manager，date、reason；保存不可变版本。
- `export` POST：manager＋近期验证，date、version；**返回 XLSX 二进制而非 JSON**，六表来自同一快照。私有磁盘路径不会返回浏览器，读取完删除临时文件。
- `settings` GET：仅 admin 可读取规则与运行结果；`settings_save` POST：仅 admin＋近期验证，以 `{"settings":{...}}` 对象提交 grade_name、check_time、due_time、reminder_time、paused_dates、retention_days、totp_student_no。冻结任务时间不被新配置改写；retention_days 不触发自动删除。修改两步验证账号另须下面的显式确认参数。
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

策略变更在同一写事务中保留目标的绑定和恢复码，清除其他账号的 TOTP 密钥／绑定／恢复码，并增加所有账号的 `auth_version`，使全部旧会话失效。成功响应 `relogin_required=true`，前端重新读取 session 并回到登录页。相同目标再次保存或仅保存日常规则返回 false，不重复清理。角色、班级授权、密码均不改变。

绑定确认和恢复码使用与策略写入使用相同的串行化写锁，重新检查当前策略／账号版本，不能用旧的待绑定 Session 在策略变更后重新启用其他账号。

维护标记期间业务接口返回 503；普通维护仍允许登录、密码恢复和验证器设置。恢复中全阻断标记连身份接口也暂停。备份、恢复、结构迁移和保留删除均为 CLI 操作，不提供 HTTP 管理后门。
