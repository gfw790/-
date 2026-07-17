# Android Studio WebView App

이 폴더는 현재 웹사이트를 안드로이드 앱처럼 실행할 수 있도록 만든 Android Studio 프로젝트입니다.

## 열기

1. Android Studio에서 `android-site-app` 폴더를 엽니다.
2. Gradle Sync가 끝나면 에뮬레이터 또는 실제 기기에서 실행합니다.

## 사이트 주소 변경

- 파일: `app/src/main/res/values/strings.xml`
- 값: `site_url`

기본값은 안드로이드 에뮬레이터에서 PC의 로컬 서버에 접속하는 주소입니다.

```xml
<string name="site_url">http://10.0.2.2/risk_server/project/</string>
```

## 주소 예시

- 로컬 Apache/PHP 서버를 에뮬레이터에서 볼 때: `http://10.0.2.2/...`
- 같은 와이파이의 실제 휴대폰에서 볼 때: `http://PC_IP_ADDRESS/...`
- 배포 서버가 있을 때: `https://your-domain.com/...`

## 포함 기능

- `WebView` 기반 사이트 표시
- 자바스크립트, DOM Storage 활성화
- 새로고침 스와이프 지원
- 뒤로가기 시 웹 히스토리 우선 이동
