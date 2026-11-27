# PHP人脸实名分发系统 2.0.4

基于 ThinkPHP 8 + Vue 3 开发的人脸实名分发系统。对接其他服务商的活体 H5 检测和人脸对比插件，实现人脸实名功能。

## 功能

- 人脸比对：姓名、身份证号、人脸照片三要素，调用比对插件判断是否同一人
- 活体检测：发起 H5 活体，手机打开采集页完成检测，回传人脸图后自动比对
- 在线测试：后台直接发起完整实名流程，查看结论、分值、单号、耗时和上游报文
- 上游插件：活体与比对两类插件，后台启用并配置各服务商参数
- 调用记录：按接口筛选，查看每次调用的状态、耗时和结果
- 对外接口：人脸比对、发起活体、查询结果三个接口，鉴权与字段见后台「接口文档」
- 站点设置：站点名称、接口密钥、活体有效期等
- 系统升级：后台上传更新包完成升级

## 环境

- PHP 8.0+，需要 pdo_mysql、curl、mbstring、openssl、fileinfo、zip、gd
- MySQL 5.7+
- Nginx 或 Apache

## 安装

1. 上传到网站根目录，站点根指向 `public/`
2. 访问 `/install`，按向导完成环境检测、数据库和站点配置
3. 安装后 `install/` 不可访问
4. 站点根 `/` 登录后台

## 伪静态

Nginx：

```nginx
location / {
    try_files $uri $uri/ /index.php?s=$uri&$args;
}
```

Apache 见 `public/.htaccess`。

## 目录

| 目录 | 内容 |
|---|---|
| `app/` | 应用代码 |
| `public/` | Web 根：入口、接口、后台资源、插件 |
| `public/plugins/` | 活体检测与人脸比对插件 |
| `public/plugins/liveness/` | 活体插件 |
| `public/plugins/compare/` | 比对插件 |
| `install/` | 安装向导与库结构 |
| `config/` | 框架配置 |
| `vendor/` | Composer 依赖 |

## 对外接口

| 接口 | 地址 |
|---|---|
| 人脸比对 | `/api/v4/facehy` |
| 发起活体 | `/api/v4/face_start` |
| 查询结果 | `/api/v4/face_query` |
| 认证结果页 | `/live` |

鉴权参数、请求字段和返回结构见后台「接口文档」页。

## 活体插件

| 插件 | 购买地址 |
|---|---|
| 阿里云市场 · 活体 H5 | https://market.aliyun.com/detail/cmapi00071987 |
| 阿里云市场 · 神度活体 H5 | https://market.aliyun.com/detail/cmapi00072156 |
| 阿里云市场 · 易顺达活体 H5 | https://market.aliyun.com/detail/cmapi00071079 |
| 阿里云市场 · 安那其炫光活体 | https://market.aliyun.com/detail/cmapi00064004 |

## 比对插件

| 插件 | 购买地址 |
|---|---|
| 阿里云市场 · 人脸身份证比对 | https://market.aliyun.com/detail/cmapi00069802 |
| 阿里云市场 · 蓝笛人脸实名 | https://market.aliyun.com/detail/cmapi00060795 |
| 阿里云市场 · 新诺韦尔人脸三要素 | https://market.aliyun.com/detail/cmapi00049446 |
| 阿里云市场 · 新诺韦尔人脸比对三要素 | https://market.aliyun.com/detail/cmapi00051762 |
| 阿里云市场 · 安那其实人认证 | https://market.aliyun.com/detail/cmapi00046900 |
| 阿里云市场 · 数脉人像比对 | https://market.aliyun.com/detail/cmapi033789 |
| 阿里云市场 · 数链云人脸三要素 | https://market.aliyun.com/detail/cmapi00050254 |
| 阿里云市场 · 怜花人脸三要素 | https://market.aliyun.com/detail/cmapi00061263 |
| 阿里云市场 · 快证签人像比对 | https://market.aliyun.com/detail/cmapi00066582 |
| 阿里云市场 · 互联三六五实人认证 | https://market.aliyun.com/detail/cmapi00037888 |

## 交流群

QQ 群：1036015220

## 版本

当前版本：**2.0.4**

升级方式：后台「系统设置 → 系统升级」上传更新包，升级前先备份数据库。

| 版本 | 内容 |
|---|---|
| 2.0.4 | 系统更名 PHP人脸实名分发系统，补充插件清单与说明 |
| 2.0.3 | 在线测试照片行调整，活体结果增加采集二维码 |
| 2.0.2 | 在线测试页，上游设置按类型显示图标 |
| 2.0.1 | 在线测试接口，上游与比对报文可查看 |
| 2.0.0 | ThinkPHP 8 重构版 |