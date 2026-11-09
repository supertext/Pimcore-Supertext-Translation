#!/usr/bin/env bash
# CI: starts the demo image twice against MySQL and OpenSearch with the stand-in API, checks the
# demo accounts (created once, never duplicated, passwords never logged) and translates the sample
# pages and article as the editor, and the Commando Center parts (public ping, one report to the
# stand-in's POST /report, never to the live center). Needs: docker image supertext-pimcore-demo, MySQL at $MYSQL_URL
# (root), OpenSearch on 127.0.0.1:9200, the `mysql` client, the stand-in on 127.0.0.1:8765, and
# PIMCORE_ENCRYPTION_SECRET / PIMCORE_INSTANCE_IDENTIFIER / PIMCORE_PRODUCT_KEY.
set -euo pipefail
PORT=8080
B=http://127.0.0.1:$PORT
DB=pimcore
export DEMO_ADMIN_EMAIL=ci-admin@example.com DEMO_ADMIN_PASSWORD="Ci-$(openssl rand -hex 12)"
export DEMO_EDITOR_EMAIL=ci-editor@example.com DEMO_EDITOR_PASSWORD="Ci-$(openssl rand -hex 12)"
export APPLICATION_SECRET="$(openssl rand -hex 32)" MERCURE_JWT_KEY="$(openssl rand -hex 32)"
q() { mysql ${MYSQL_ARGS:--h 127.0.0.1 -P 3306 -uroot -proot} -N --default-character-set=utf8mb4 "$DB" -e "$1"; }
console() { docker exec -u www-data demo bin/console "$@"; }

start() {
	docker rm -f demo >/dev/null 2>&1 || true
	docker run -d --name demo --network host -e PORT=$PORT -e MYSQL_URL -e PIMCORE_DB_SERVER_VERSION=8.0.0 \
		-e PIMCORE_OPENSEARCH_DSN='opensearch://127.0.0.1:9200?ssl=false' -e PUBLIC_URL=$B \
		-e APPLICATION_SECRET -e MERCURE_JWT_KEY \
		-e PIMCORE_ENCRYPTION_SECRET -e PIMCORE_INSTANCE_IDENTIFIER -e PIMCORE_PRODUCT_KEY \
		-e DEMO_ADMIN_EMAIL -e DEMO_ADMIN_PASSWORD -e DEMO_EDITOR_EMAIL -e DEMO_EDITOR_PASSWORD \
		-e SUPERTEXT_API_KEY=anything -e SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/ \
		-e SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report supertext-pimcore-demo >/dev/null
	for _ in $(seq 180); do curl -sf -o /dev/null $B/pimcore-studio/ && break; sleep 3; done
	curl -sf -o /dev/null $B/pimcore-studio/
	docker logs demo 2>&1 | grep '\[demo\]' || true
}

start
start   # second start: nothing duplicated or changed
logs=$(docker logs demo 2>&1)
grep -qF 'DEMO_EDITOR: account exists, left unchanged' <<< "$logs"
if grep -qF -e "$DEMO_ADMIN_PASSWORD" -e "$DEMO_EDITOR_PASSWORD" <<< "$logs"; then echo "A password appeared in the log"; exit 1; fi

test "$(q "select count(*) from users where type='user' and name in ('ci-admin@example.com','ci-editor@example.com')")" = 2
test "$(q "select admin from users where name='ci-admin@example.com'")" = 1
test "$(q "select r.name from users u join users r on r.id=u.roles where u.name='ci-editor@example.com'")" = Editors
q "select permissions from users where type='role' and name='Editors'" | grep > /dev/null supertext_translate
test "$(q "select count(*) from users_permission_definitions where \`key\`='supertext_translate'")" = 1
echo "accounts and permission OK"

console supertext:check | tee /tmp/check.log
grep -qF 'API key checked: the connection to Supertext works.' /tmp/check.log
grep -qF 'de_CH → de-CH (more)' /tmp/check.log
grep -qF 'en → en-GB' /tmp/check.log

# Commando Center: the public ping (no login), and an "installed" report after a back-end request.
ping=$(curl -s -w ' %{http_code}' "$B/supertext/ping?challenge=ci-challenge-0123456789")
test "$ping" = '{"ok":true,"plugin":"Pimcore-Supertext-Translation","challenge":"ci-challenge-0123456789"} 200'
test "$(curl -s -o /dev/null -w '%{http_code}' "$B/supertext/ping?challenge=x")" = 400
jar=$(mktemp)
curl -sf -c "$jar" -b "$jar" -H 'Content-Type: application/json' \
	-d "{\"username\":\"$DEMO_ADMIN_EMAIL\",\"password\":\"$DEMO_ADMIN_PASSWORD\"}" $B/pimcore-studio/api/login > /dev/null
settings=$(curl -sf -b "$jar" $B/pimcore-studio/api/supertext/settings)
grep -qF '"reports":true' <<< "$settings"
grep -qF '"code":"de-CH"' <<< "$settings"
grep -qF '"saved":"en-GB"' <<< "$settings"   # the demo's mapping, on Supertext → Settings
# Supertext → Settings: save the mapping (the same values), refuse codes Supertext doesn't offer.
mapping='{"languages":{"en":{"code":"en-GB","politeness":""},"de_CH":{"code":"de-CH","politeness":"more"},"fr_CH":{"code":"fr-CH","politeness":"more"},"it_CH":{"code":"it-CH","politeness":"more"}}}'
saved=$(curl -sf -b "$jar" -H 'Content-Type: application/json' -H 'X-Supertext-Request: 1' -d "$mapping" $B/pimcore-studio/api/supertext/settings/languages)
grep -qF '"saved":"it-CH"' <<< "$saved"
test "$(curl -s -o /dev/null -w '%{http_code}' -b "$jar" -H 'Content-Type: application/json' -H 'X-Supertext-Request: 1' -d '{"languages":{"de_CH":{"code":"de-LI"}}}' $B/pimcore-studio/api/supertext/settings/languages)" = 400
test "$(curl -s -o /dev/null -w '%{http_code}' -b "$jar" -H 'Content-Type: application/json' -d "$mapping" $B/pimcore-studio/api/supertext/settings/languages)" = 400
for _ in $(seq 10); do
	state=$(q "select data from settings_store where id='reporter' and scope='supertext_translation'")
	grep -qF '"reportedVersions":"' <<< "$state" && break
	sleep 1
done
grep -qF '"reportedVersions":"' <<< "$state"
grep -qF '"status":"ok"' <<< "$state"
if grep -qF 'anything' <<< "$state"; then echo "The API key appeared in the stored state"; exit 1; fi
rm -f "$jar"
echo "Commando Center OK"
# The product page needs its parent's translation first.
out=$(console supertext:translate --document=/en/swiss-chocolate --user=ci-editor@example.com 2>&1 || true)
grep -qF 'Translate the parent page into this language first' <<< "$out"
console supertext:translate --document=/en --user=ci-editor@example.com | tee /tmp/translate.log
console supertext:translate --document=/en/swiss-chocolate --user=ci-editor@example.com | tee -a /tmp/translate.log
console supertext:translate --object=/Articles/swiss-chocolate --from=en --user=ci-editor@example.com | tee -a /tmp/translate.log
test "$(grep -c ': translated' /tmp/translate.log)" = 9
console supertext:translate --document=/en/swiss-chocolate --to=de_CH --user=ci-editor@example.com | grep > /dev/null 'skipped'

test "$(q "select p.title from documents d join documents_page p on p.id=d.id where d.path='/de-ch/' and d.\`key\`='schweizer-schokolade'")" = "Schweizer Schokolade, weltweit versandt"
test "$(q "select published from documents where path='/fr-ch/' and \`key\`='chocolat-suisse'")" = 0
q "select data from documents_editables e join documents d on d.id=e.documentId where d.path='/de-ch/' and e.name='content'" | grep > /dev/null '<strong>Berner</strong>'
q "select data from documents_editables e join documents d on d.id=e.documentId where d.path='/it-ch/' and e.name='cta'" | grep > /dev/null 'Ordini una scatola di degustazione'
test "$(q "select count(*) from documents_translations")" = 6
test "$(q "select count(*) from notes where type='supertext'")" = 9

# Export for translation → Import translation through the Studio API (the article, German).
jar=$(mktemp)
curl -sf -c "$jar" -b "$jar" -H 'Content-Type: application/json' \
	-d "{\"username\":\"$DEMO_EDITOR_EMAIL\",\"password\":\"$DEMO_EDITOR_PASSWORD\"}" $B/pimcore-studio/api/login > /dev/null
test "$(curl -s -o /dev/null -w '%{http_code}' -b "$jar" -H 'Content-Type: application/json' -H 'X-Supertext-Request: 1' -d '{"languages":{}}' $B/pimcore-studio/api/supertext/settings/languages)" = 403
article=$(q "select id from objects where path='/Articles/' and \`key\`='swiss-chocolate'")
xliff=$(mktemp)
headers=$(curl -sf -b "$jar" -D - -o "$xliff" "$B/pimcore-studio/api/supertext/elements/data-object/$article/export?source=en&targets=de_CH&format=xliff&overwrite=1")
grep -qi '^content-type: application/x-xliff+xml' <<< "$headers"
grep -qi "^content-disposition: attachment; filename=\"pimcore-object-$article-de_CH.xlf\"" <<< "$headers"
grep -qF "original=\"object:$article:en:de_CH\"" "$xliff"
python3 - "$xliff" <<'PY'
import re, sys
path = sys.argv[1]
xliff = open(path, encoding='utf-8').read()
xliff, n = re.subn(r'(<trans-unit id="([^"]+)" datatype="plaintext"[^>]*>\s*<source>.*?</source>)', r'\1<target>Aus der Datei</target>', xliff, count=1, flags=re.S)
assert n == 1, 'no plain-text unit'
open(path, 'w', encoding='utf-8').write(xliff)
PY
result=$(curl -sf -b "$jar" -H 'X-Supertext-Request: 1' -F "file=@$xliff" -F overwrite=1 $B/pimcore-studio/api/supertext/import)
echo "$result"
grep -qF '"imported":1' <<< "$result"
grep -qF '"error":null' <<< "$result"
test "$(curl -s -o /dev/null -w '%{http_code}' -b "$jar" -F "file=@$xliff" $B/pimcore-studio/api/supertext/import)" = 400
test "$(q "select count(*) from notes where type='supertext'")" = 10
rm -f "$jar" "$xliff"
echo "Demo check passed"
