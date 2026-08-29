# WP Rescue

[English](README.md)

WP Rescueは、セルフホスト型WordPressサイトを構築・検証するDocker Compose基盤です。MariaDBを公開プロキシネットワークから分離し、プラグインのソースマウント開発環境と、配布ZIPのクリーン試験環境を分けて用意します。

> **アルファ版:** 本リポジトリは運用基盤のひな型であり、安全性を保証するマネージドサービスではありません。DNS、TLS、更新、WordPressの堅牢化、バックアップ、復元試験、監視、インシデント対応は運用者の責任です。

## 収録する環境

| Composeファイル | 用途 | ホスト側の公開 |
| --- | --- | --- |
| `docker-compose.yml` | 外部Traefik配下で使う本番向け構成 | WordPressの直接公開なし |
| `docker-compose.local.yml` | プラグインのローカル開発 | `127.0.0.1:8081` |
| `docker-compose.ziptest.yml` | 配布ZIPのクリーンインストール試験 | `127.0.0.1:8082` |

WordPress、MariaDB、プラグイン配布物、認証情報、証明書、本番データはリポジトリに含みません。

## 必要なもの

- Linux上のDocker EngineとDocker Compose v2
- WSL2上のDocker Engineにも対応します。Docker Desktopは不要です
- 本番公開時は、[traefik-rescue](https://github.com/ozekihiroshi/traefik-rescue)等が提供する外部Traefikネットワーク
- ソースマウント開発時は、同じ親フォルダにある`secure-s3-storage-for-wordpress`

## ローカル開発

```bash
cp .env.example .env
# 2つのchange_meを必ず変更します。
docker compose -f docker-compose.local.yml up -d --build
```

<http://localhost:8081> を開きます。名前付きボリュームは`down`で残ります。ローカルのサイトとDBを意図的に消す場合を除き、`--volumes`を付けないでください。

## 配布ZIPのクリーン試験

ZIP試験環境は、意図的にプラグインのソースをマウントしません。

```bash
docker compose -f docker-compose.ziptest.yml up -d --build
```

<http://localhost:8082> を開き、管理画面またはWP-CLIから配布ZIPをインストールします。

## 本番利用

1. 外部ゲートウェイを起動し、共有ネットワークを作ります（既定値`rescue_proxy`）。
2. `.env.example`を`.env`へコピーし、認証情報と`WORDPRESS_HOST`を設定します。
3. 構成を検証して起動します。

```bash
docker compose --env-file .env config --quiet
docker compose up -d
```

公開プロキシネットワークへ参加するのはWordPressだけです。MariaDBは非公開ネットワークに置かれ、ホスト側ポートを公開しません。[Traefik Rescueを使った本番構築](docs/production-with-traefik-rescue.md)と[バックアップと復元](docs/backup-and-restore.md)も確認してください。

## 設定検証

```bash
docker compose --env-file .env.example -f docker-compose.yml config --quiet
docker compose --env-file .env.example -f docker-compose.local.yml config --quiet
docker compose --env-file .env.example -f docker-compose.ziptest.yml config --quiet
```

## セキュリティ上の境界

- `.env`、SQLダンプ、鍵、証明書、本番アップロードをコミットしないでください。
- 本番ではイメージのパッチ版またはdigestを固定し、事前試験してください。
- DBとWordPressファイルの両方をバックアップし、隔離環境で復元試験してください。
- WordPress、テーマ、プラグイン、イメージ、Docker Engine、ホストOSを更新してください。

脆弱性の報告は[SECURITY.md](SECURITY.md)、アルファ版の制限は[CHANGELOG.md](CHANGELOG.md)を参照してください。

## ライセンス

Copyright 2026 Hiroshi Ozeki. GNU General Public License version 3またはそれ以降。全文は[LICENSE](LICENSE)を参照してください。
