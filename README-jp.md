# wp-rescue

WordPress復旧・移行・VPSトラブル対応の検証・実演用Docker環境。

この環境は、既存の `demand-monitor` Docker基盤とは分離して構築する。
ただし、外部公開には既存の Traefik ネットワーク `demand-monitor_web` を利用する。

## Purpose

この環境の目的は、WordPressに関する以下の作業を実際に検証・説明できる形で整備することである。

* WordPressサイトの新規構築
* WordPressサイトの移行
* WordPressのバックアップとリストア
* VPS上の容量不足・Docker障害・SSL/DNSトラブル対応
* WordPress復旧支援サービス用の実物サイト構築
* トラブル対応事例の記録と記事化

## Directory Structure

```text
~/docker/wp-rescue/
├── docker-compose.yml
├── README.md
├── wordpress/
│   └── uploads.ini
└── backups/
```

## Services

想定する主なサービスは以下の通り。

```text
wp-rescue       WordPress本体
wp-rescue-db    WordPress専用データベース
wp-cli          WordPress操作用CLI
```

## Network Design

この環境では、アプリ内部通信用ネットワークと公開用ネットワークを分離する。

```text
internal
  WordPress と DB 間の内部通信に使用する。

dm_web
  既存 Traefik から WordPress を公開するために使用する。
  実体は demand-monitor_web。
```

外部公開用ネットワークは以下を利用する。

```yaml
networks:
  dm_web:
    external: true
    name: demand-monitor_web
```

## Domain

公開用ドメイン候補：

```text
wp.ceri.link
```

DNSのAレコードは、EC2インスタンスの Public IPv4 に向ける。

## Start

```bash
cd ~/docker/wp-rescue
docker compose config
docker compose up -d
docker compose ps
```

## Stop

```bash
cd ~/docker/wp-rescue
docker compose stop
```

## Restart

```bash
cd ~/docker/wp-rescue
docker compose restart
```

## Logs

```bash
cd ~/docker/wp-rescue
docker compose logs --tail=100
```

WordPressのみ確認する場合：

```bash
docker compose logs --tail=100 wp-rescue
```

DBのみ確認する場合：

```bash
docker compose logs --tail=100 wp-rescue-db
```

## Backup Policy

この環境では、WordPress本体ファイルとDBを分けて考える。

* WordPressファイル
* uploads
* themes
* plugins
* database dump

バックアップ保存先：

```text
~/docker/wp-rescue/backups/
```

DBバックアップ例：

```bash
docker compose exec -T wp-rescue-db mariadb-dump -u root -p wp_rescue > backups/wp_rescue_$(date +%Y%m%d_%H%M%S).sql
```

※ 実際のパスワードやDB名は `docker-compose.yml` の内容に合わせる。

## Important Notes

この環境では、既存の `demand-monitor` や `keiba-signal` のコンテナ・DB・ボリュームを直接利用しない。

WordPress環境を破棄する場合でも、原則として以下は安易に実行しない。

```bash
docker compose down -v
```

`-v` を付けると、DBやWordPressファイルを格納するDockerボリュームが削除される可能性があるため注意する。

## Current Status

初期構築段階。

作成済みファイル：

```text
docker-compose.yml
wordpress/uploads.ini
README.md
```

次に行う作業：

```text
1. docker compose config で構文確認
2. DNS Aレコード確認
3. docker compose up -d
4. WordPress初期セットアップ
5. バックアップ・リストア手順の整備
6. WordPress復旧・移行サービス用コンテンツ作成
```

## Operational Memo

今回の環境は、単なるWordPressサイトではなく、WordPress復旧・移行・VPSトラブル対応の実例を蓄積するための基盤である。

特に以下のような実トラブル事例を記録対象とする。

* EBS容量不足
* Dockerコンテナの固着
* `Stopping` のまま進まないコンテナ
* Docker daemon / containerd の再起動
* EC2 Reboot / Stop-Start の使い分け
* WordPress DB復旧
* WordPressファイル移行
* SSL証明書・Traefik・DNSの問題

## Site Concept

サイト名候補：

- WP Rescue Lab
- WPレスキューラボ

このWordPressサイトは、WordPress復旧・移行・VPSトラブル対応を扱う日英対応サイトとして構築する。

主言語は日本語、副言語は英語とする。  
日本国内向けの支援内容を日本語で整理しつつ、海外案件・ポートフォリオ向けに英語ページも整備する。

想定コンテンツ：

- WordPress recovery
- WordPress migration
- VPS troubleshooting
- Docker / Traefik / Nginx recovery
- DNS / SSL troubleshooting
- Backup and restore workflows