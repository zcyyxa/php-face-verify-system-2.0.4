# PHP人脸实名分发系统 2.0.4

基于 ThinkPHP 8 + Vue 3 + Element Plus 的人脸实名认证分发系统。站根就是后台，没有单独的 admin 目录。

## 环境

- PHP 8.1+，需要 pdo_mysql、curl、mbstring、openssl、fileinfo、zip、gd
- MySQL 5.7+
- Nginx 或 Apache

## 安装

1. 上传到网站根目录，站点根指向 `public/`
2. 浏览器打开 `/install`，按向导完成环境检测、数据库和站点配置
3. 安装完成后 `install/` 自动不可访问
4. 后台就是站点根 `/`，用安装时设置的管理员账号登录

## 伪静态

Nginx：

```nginx
location / {
    try_files $uri $uri/ /index.php?s=$uri&$args;
}
```

Apache 规则见 `public/.htaccess`。

## 目录

| 目录 | 内容 |
|---|---|
| `app/` | 应用代码 |
| `public/` | Web 根：入口、接口、后台资源、插件 |
| `public/plugins/` | 活体检测与人脸比对插件 |
| `install/` | 安装向导与库结构 |
| `config/` | 框架配置 |
| `vendor/` | Composer 依赖 |

## 版本

2.0.4