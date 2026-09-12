# Development and operations

WordPress의 MariaDB에 페이지뷰를 저장하고 WordPress 관리자 **Analytics** 메뉴에서 React 통계를 표시하는 플러그인입니다. 별도 백엔드나 운영 Node.js 서버가 필요하지 않습니다.

## 코드 구성과 포맷

관리자 UI와 오류·설정 문구는 영어로 표시합니다. `src/api.ts`는 REST 요청, `src/useStatistics.ts`는 요청 상태, `src/components/`는 개별 통계 표시를 맡습니다. PHP는 수집 검증, 지표별 조회, migration 단계, importer 배치 처리를 별도 함수로 분리합니다.

`npm run format`으로 PHP·TypeScript·JavaScript·CSS를 정리하고 `npm run format:check`으로 확인합니다. PHP는 4칸, 프런트엔드는 2칸 들여쓰기를 사용합니다. 압축 파일은 `assets/admin/`의 배포용 빌드에만 생성하며 직접 편집하지 않습니다.

## 개발과 설치

필요 도구: Node.js 22.12 이상(빌드), npm, Python 3(ZIP 생성). 운영 WordPress에는 PHP 8.0 이상과 MariaDB/MySQL이 필요합니다. 현재 검증 환경은 WordPress 7.1, PHP 8.3, MariaDB입니다.

```sh
npm ci
npm run build
npm run lint
npm run format:check
./build-zip.sh --no-build
```

`dist/omongstat.zip`을 WordPress의 플러그인 업로드 화면에서 설치·활성화합니다. ZIP 최상위는 `omongstat/`이며 PHP, collector, React 빌드, CLI importer만 들어갑니다. `node_modules`, `src`, 배포 스크립트, 환경 설정 파일은 포함하지 않습니다. 관리자 빌드는 `wordpress_plugins/omongstat/assets/admin/`에 생성되고 PHP가 `.vite/manifest.json`을 읽습니다.

Docker 개발 환경 배포:

```sh
bash wordpress_plugins/omongstat/deploy-plugin.sh --build
```

기본 컨테이너는 `wordpress-web`이며 `WORDPRESS_CONTAINER`로 변경할 수 있습니다. 설치 경로는 `/var/www/html/wp-content/plugins/omongstat`입니다. 스크립트는 staging 전송과 collector·manifest·JS/CSS 검증 후 교체합니다. 이전 설치본은 출력되는 `.omongstat.stage.*.previous` 경로에 남기고 교체 실패 시 복구합니다. 동시 배포는 잠금 디렉터리로 차단합니다. 처음 설치했다면 WordPress 관리자에서 활성화하세요.

## 수집과 통계

공개 페이지에서 페이지당 한 번 `POST /wp-json/omongstat/v1/collect`로 JSON을 보냅니다. 정상 요청은 본문 없는 204, 잘못된 값은 400 또는 413, DB 실패는 500입니다. 개발 시 `WP_DEBUG`가 켜져 있으면 collector 전송 오류가 콘솔에 표시됩니다. Beacon의 `true`는 브라우저가 전송을 접수했다는 의미이며 서버 저장 성공을 보장하지 않습니다.

수집 필드: 내부 `path`(쿼리·fragment 제외, 최대 2048바이트), 정수 `postId`, `referrer`(최대 4096바이트), `visitorId`·`sessionId`(영문/숫자/밑줄/하이픈 16–64자), `language`(64바이트), `timezone`(128바이트), 정수 `screenWidth`·`screenHeight`(0–65535), `eventType: pageview`. 식별자는 localStorage/sessionStorage에 유지하고 차단된 환경에서는 해당 페이지에서만 사용할 ID를 생성합니다. 브라우저의 sessionStorage 복제 동작에 따라 복제 탭이 처음에는 같은 세션 ID를 가질 수 있습니다.

IP는 서버에서 읽어 `wp_salt('auth')`를 키로 HMAC-SHA256 해시만 저장합니다. 원본 UA는 저장하고 브라우저·OS·기기·봇 추정치를 가볍게 분류합니다. 실시간 봇 페이지뷰는 포함하여 분류하고 로그 importer는 명백한 봇을 제외합니다. 화면 크기나 국가 등 정보가 없으면 통계에서 Unknown 또는 미집계로 나타납니다.

Analytics 하단 설정에서 관리자 방문 제외(기본 켜짐)와 제거 시 데이터 삭제(기본 꺼짐)를 변경할 수 있습니다. 비활성화 시 데이터는 삭제하지 않습니다. 제거 시 삭제 옵션이 켜진 경우만 이벤트 테이블과 플러그인 옵션을 삭제합니다. 현재 설치·제거 처리는 사이트 단위이며 멀티사이트 네트워크 일괄 배포는 지원 범위에 포함하지 않습니다.

### 신뢰할 프록시 설정

전달 헤더는 기본적으로 신뢰하지 않습니다. 서버 측 MU 플러그인 또는 관리 코드에서 **직접 연결하는 프록시의 실제 주소**를 지정하세요.

```php
add_filter('omongstat_trusted_proxies', function () {
    return ['192.0.2.10']; // 예시 주소: 실제 Nginx/프록시 주소로 교체
});
```

해당 프록시가 외부에서 임의로 지정한 전달 헤더를 제거·재작성하는 환경에서만 설정하세요. 신뢰한 연결에서는 유효한 `CF-Connecting-IP`, 신뢰 체인을 역순으로 확인한 `X-Forwarded-For`, `REMOTE_ADDR` 순으로 사용합니다. `CF-IPCountry`는 신뢰한 연결의 두 자리 영문 코드만 허용하고 `XX`와 `T1`은 저장하지 않습니다. 프록시 신뢰 설정이 없으면 연결 IP의 해시만 저장하고 국가는 NULL입니다. Cloudflare IP 대역이나 프록시 주소를 자동 추측하지 않습니다.

### 관리자 API

모든 조회는 `manage_options` 권한과 유효한 `X-WP-Nonce`(`wp_rest`)가 필요합니다. WordPress 로그인 쿠키로 인증하며 공개 수집에는 nonce가 필요하지 않습니다.

| GET 경로 (`/wp-json/omongstat/v1/` 기준) | 응답 |
| --- | --- |
| `stats/summary` | `total`, `today`, `pageviews`, `visitors`, `sessions` 정수 |
| `stats/timeseries` | 날짜별 `{label, count}` 배열, 0건 날짜 포함 |
| `stats/pages`, `stats/referrers`, `stats/countries` | `{label, count}` 상위 50개 |
| `stats/technology` | `browser`, `operating_system`, `device_type`, `is_bot`, `screen` 배열 |
| `events/recent` | 선택 기간 최근 이벤트 최대 50개 |

`start`, `end`는 `YYYY-MM-DD`이며 양 끝 날짜를 포함합니다. 기본 최근 7일, 최대 366일입니다. DB 저장은 UTC, 날짜 경계와 표시 시각은 WordPress 사이트 시간대입니다. 일광절약시간 변경은 PHP에서 날짜별 UTC 경계를 계산해 처리합니다. 전체·오늘 지표는 선택 기간과 독립적입니다. 과거 이벤트에 ID가 없으면 고유 방문자/세션 수에서 제외합니다. SQL 오류는 0건으로 변환하지 않고 500과 서버 로그를 남깁니다. 큰 테이블에서는 기간과 그룹 집계 비용이 증가하므로 현재 버전은 원본 이벤트 기반 통계입니다.

## DB 업그레이드

플러그인 코드 버전과 `omongstat_schema_version`은 별도로 관리합니다. 신규 설치와 업데이트 시 필요한 컬럼·인덱스를 확인하고 migration을 실행합니다. 이전 `occured_at` 컬럼은 명시적으로 `occurred_at`으로 변경합니다. 두 컬럼에 서로 다른 날짜가 있으면 값을 삭제하지 않고 migration을 중단하여 로그와 관리자 오류를 표시합니다. 원본 DB를 백업한 뒤 충돌값을 확인·정리하고 다음 요청에서 재시도하세요. 과거 `path = 0` 데이터는 원래 경로를 복원할 수 없으므로 임의로 바꾸지 않습니다. 기존 UA는 배치로 분류하고 없는 방문자 ID 등을 만들어내지 않습니다.

## 과거 access log 가져오기

Docker API 없이 내보낸 파일을 한 줄씩 읽습니다. Apache/Nginx combined 형식을 지원하며 Docker의 서비스·timestamp 접두어도 처리합니다. GET/HEAD, 2xx/3xx 페이지 요청을 대상으로 관리자·REST·로그인·cron·feed·정적 리소스·명백한 봇을 제외합니다. 비대상/파싱 불가 행은 `excluded`, 잘못된 timestamp·과도하게 긴 행·DB 실패는 `errors`에 집계합니다.

```sh
docker compose logs --no-color --no-log-prefix wordpress > wordpress-access.log
docker cp wordpress-access.log wordpress-web:/tmp/wordpress-access.log
docker exec wordpress-web php /var/www/html/wp-content/plugins/omongstat/bin/import.php --file=/tmp/wordpress-access.log --dry-run
docker exec wordpress-web php /var/www/html/wp-content/plugins/omongstat/bin/import.php --file=/tmp/wordpress-access.log
```

Nginx 로그도 같은 방식으로 파일 경로를 전달합니다. WordPress 경로가 다르면 `--wp-load=/path/to/wp-load.php`를 지정합니다. WP-CLI가 있다면 `wp omongstat import /tmp/wordpress-access.log --dry-run`도 가능합니다.

- 250행 배치 트랜잭션으로 저장합니다. 실패한 배치는 rollback하고 오류 수를 출력합니다. 전체 파일 단일 트랜잭션이 아니므로 실패 이전 배치는 유지되며 재실행으로 이어갈 수 있습니다.
- `lines`, `imported`, `eligible`(dry-run), `excluded`, `duplicates`, `errors`를 출력합니다. 오류가 있으면 종료 코드는 1입니다.
- 행의 앞뒤 공백을 제거한 SHA-256을 `source_event_id`로 사용합니다. 같은 행의 재가져오기는 중복 저장하지 않습니다. dry-run도 임시 DB 테이블로 배치 간 중복을 판정하지만 이벤트 테이블은 변경하지 않습니다.
- 동일 사이트의 importer 동시 실행은 잠금으로 차단합니다. 로그 파일을 변경하거나 접두어를 바꾸면 행 해시가 달라집니다.
- 로그의 IP는 기본적으로 저장하지 않습니다. 실제 방문자 주소임을 확인한 로그에만 `--trust-log-ip`를 사용하면 HMAC으로 저장합니다. Cloudflare/프록시 주소를 실제 사용자로 간주하지 마세요.
- 로그에 없는 visitor/session ID, 국가, 화면 크기, 언어는 NULL입니다. 과거 Cloudflare edge에서 처리되어 원본 서버에 도달하지 않은 요청은 복원할 수 없습니다.

## 캐시·최적화

OmongStat REST 응답은 `Cache-Control: no-store, private, max-age=0`을 보냅니다. Cloudflare와 Nginx에서도 `/wp-json/` 및 plain permalink의 `?rest_route=` 요청을 캐시 제외하세요. 원본 응답 헤더만으로 CDN의 강제 캐시 규칙을 무효화할 수는 없습니다.

배포 후 순서:

1. WordPress 페이지 캐시와 Autoptimize JS/CSS 캐시를 삭제합니다.
2. Cloudflare 대시보드의 캐시 제거에서 해당 사이트의 HTML과 기존 최적화 번들을 제거합니다. 전체 사이트 제거가 허용되는 환경이면 전체 캐시 제거도 가능합니다.
3. 로그아웃한 브라우저의 Network에서 새 collector/최적화 번들이 200이고 collect POST가 204인지 확인합니다.
4. 관리자 Analytics를 새로고침하여 실제 이벤트를 확인합니다. 관리자 방문은 기본 제외되므로 별도 로그아웃 창을 사용합니다.

collector URL 버전은 `filemtime`입니다. Autoptimize가 설정을 `data:` 스크립트로 옮겨도 설정이 collector보다 먼저 실행되어야 합니다. 오래된 번들이 의심되면 HTML의 `omongstat-collector-js-extra`와 `omongstat-collector-js` 순서, URL 버전, 실제 응답의 `visitorId` 필드를 확인하세요. 설치 전 캐시된 HTML에는 collector가 전혀 없을 수 있습니다. CSP나 JS 지연 실행 정책이 있다면 설정과 collector가 함께 실행되는지도 확인하세요.

## 검증

```sh
npm run build
npm run lint
npm run format:check
node --test tests/collector.test.mjs
python3 tests/deployment.py
bash -n build-zip.sh wordpress_plugins/omongstat/deploy-plugin.sh
./build-zip.sh --no-build
unzip -t dist/omongstat.zip
```

PHP가 호스트에 없는 환경에서의 검사:

```sh
docker exec wordpress-web mkdir -p /tmp/omongstat-verification/wordpress_plugins/omongstat /tmp/omongstat-verification/tests
docker cp wordpress_plugins/omongstat/. wordpress-web:/tmp/omongstat-verification/wordpress_plugins/omongstat/
docker cp tests/. wordpress-web:/tmp/omongstat-verification/tests/
docker exec wordpress-web sh -c 'find /tmp/omongstat-verification -name "*.php" -exec php -l {} \;'
docker exec wordpress-web php /tmp/omongstat-verification/tests/integration.php
```

통합 검증은 해당 PHP 프로세스에서 기존 플러그인 로딩을 끄고 무작위 `omongstat_test_*` 테이블을 만들어 제거합니다. 실제 이벤트 테이블과 옵션은 변경하지 않습니다. 신규 설치, 원래 6컬럼 스키마 업그레이드, timestamp 충돌 보존, ID/IP/path 저장, 모든 통계 경로, 잘못된 입력, DB 실패, importer 재실행을 검사합니다. 의도적인 DB 오류 테스트 로그는 정상입니다.

배포 후 실제 HTTP 확인:

```sh
docker exec wordpress-web php /tmp/omongstat-verification/tests/live-smoke.php
```

이 검사는 컨테이너 내부 Apache에 요청하여 collector·React JS·Autoptimize 번들 200, 설정 순서, collect 204, DB 증가, 400/403 및 캐시 헤더를 확인합니다. 자체 생성한 식별자로 저장된 테스트 이벤트만 마지막에 삭제합니다. 프록시/CDN 경유 검증이나 브라우저에서의 최종 시각 확인을 대체하지 않습니다.

2026-09-12 검증 결과: React 빌드·lint 통과, collector 회귀 테스트 통과, 배포 성공/검증 실패/교체 실패 복구 테스트 3개 통과, WordPress/MariaDB 통합 검사 49개 통과, 배포 후 HTTP 검사 13개 통과. 기존 이벤트 3건은 유지했습니다. 브라우저 UI 시각 검증과 Cloudflare 외부 경로 검증은 수행하지 않았습니다.
