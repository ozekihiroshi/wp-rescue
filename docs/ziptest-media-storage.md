# 8082メディアバックアップ用の非公開作業領域

`docker-compose.ziptest.yml` だけの設定です。8081のソースマウント環境、
Traefik用の本番構成、AWSの認証情報には変更を加えません。

## 保存先と権限

- 専用の名前付きボリューム: `wp_rescue_ziptest_media_work`
- マウント先: `/var/lib/odbfs3-media`（`/var/www/html` の外）
- 作業ディレクトリ: `/var/lib/odbfs3-media/work`
- 所有者: UID/GID `33:33`（Apache版WordPressの `www-data`）
- 作業ディレクトリの権限: `0700`
- WebとWP-CLIの `WORDPRESS_CONFIG_EXTRA` から
  `ODBFS3_MEDIA_WORK_DIR` を設定

初期化専用サービス `media-work-init` は、ネットワークを使わずに上記の
ディレクトリだけを作成します。すでに存在する場合は所有者・権限を検査し、
不適切なら停止します。再帰的なchown/chmod、既存ファイルの削除は行いません。
Web/WP-CLIは初期化成功後に起動します。WP-CLIも `33:33` で実行し、
Alpineイメージの既定UIDとの差をなくしています。

既存の `wp-config.php` がDocker公式イメージの `WORDPRESS_CONFIG_EXTRA` を
評価する形式であることが必要です。独自のwp-config.phpでは自動反映されません。
パスだけの設定であり、AWSキーやパスワードは追加しません。

## 既存8082を更新する場合の注意

Composeプロジェクト名は、名前付きボリュームやネットワークの名前にも影響します。
まず実際のコンテナの値を確認してください。

```bash
docker inspect wp-rescue-ziptest \
  --format '{{index .Config.Labels "com.docker.compose.project"}}'
docker inspect wp-rescue-ziptest \
  --format '{{range .Mounts}}{{println .Type .Name .Destination}}{{end}}'
```

今回のローカル8082は **`wp-rescue`** という旧プロジェクト名で稼働しているため、
同名を明示して既存HTML/DBを維持します。新規環境はComposeファイルの既定名
`wp-rescue-ziptest` を使用できますが、稼働中の環境で安易に名前を変更しないでください。

この旧環境で設定を適用したコマンドは以下です。実行前に既存DB接続設定、
HTML/DBボリューム名、ネットワーク、イメージが変わらないことを確認しました。

```bash
cd /mnt/d/workspace/wp-rescue
docker compose -p wp-rescue -f docker-compose.ziptest.yml \
  run --rm --no-deps --pull never media-work-init
docker compose -p wp-rescue -f docker-compose.ziptest.yml \
  up -d --no-deps --no-build --pull never --force-recreate wp-rescue-ziptest
```

`--no-deps` によりDBや8081は再作成しません。孤立コンテナの警告が出ても
`--remove-orphans` を付けないでください。`down --volumes` やボリュームの削除は
行わないでください。Webコンテナの `/tmp` は再作成時に失われるため、必要な
試験記録は先に別の場所へ退避します。作業ボリュームはコンテナとは別に保持されます。

## 確認範囲と次の試験

プラグインの `MediaWorkConfiguration` で実行ユーザーのアクセス権、0700、
所有者、公開領域の外、シンボリックリンクの排除を検証します。
開始ボタンの事前検査はAWS接続の成功までは保証しません。

この設定作業ではメディアジョブを開始しません。AWS認証の追加、バケットの変更、
年月フォルダやメディア試験データの作成も別途行います。開始試験に進む前に
保存済みのS3設定と認証方法を確認してください。

実際のバックアップは作業ボリューム内にチェックポイント等を保存します。
ボリュームの永続化だけではホスト故障への備えにはならず、必要容量の監視や
終了後の保守も必要です。プラグインは現段階で私有作業ファイルを自動削除しません。

## 2026-08-31 ローカル8082での設定確認

- WordPress 7.0.2 / PHP 8.3.33。従来のHTML・DBボリュームとネットワークを保持。
- 作業ボリュームの実名は `wp-rescue_wp_rescue_ziptest_media_work`。
- Webの再作成前後で設定、有効プラグイン、ジョブ記録、プラグインCronのハッシュが一致。
- DBと8081はコンテナID・起動日時とも不変。プラグイン全ファイルのSHA-256も不変。
- 0700 / 33:33、公開領域外の事前検査が成功。管理画面のPHP描画で開始ボタンが有効。
- Webユーザーで0600の専用試験ファイルを作り、ロック・書込み・fsyncを確認。
  初期化再実行後も内容を維持し、ネットワークなし・同UIDの新規コンテナから
  読み取り専用マウントでSHA-256が一致。別UIDのディレクトリアクセスは拒否。
  この試験ファイルだけを照合後に除去し、バックアップやジョブは作成していない。
- WP-CLIもUID 33で同じパスの事前検査に成功。8082ログイン画面はHTTP 200。
- ポートはComposeの設定どおり `127.0.0.1:8082` に限定。
- AWS認証情報の追加や接続試験、管理画面からの開始、S3送信は実施していない。
- Web再作成前の配布試験記録は、プラグインリポジトリの無視対象
  `build/admin-ui-71a511e/8082-pre-recreate-evidence/` に退避済み。
