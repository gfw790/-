import json
import sys
import time

from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait


BASE_URL = "http://127.0.0.1/risk_assessment"
LOGIN_ID = "admin01"
PASSWORD = "123456789"
REPORT_ID = "301"
CHROME_BINARY = r"C:\Program Files\Google\Chrome\Application\chrome.exe"


def build_driver():
    options = Options()
    options.binary_location = CHROME_BINARY
    options.add_argument("--headless=new")
    options.add_argument("--disable-gpu")
    options.add_argument("--window-size=1600,2600")
    options.set_capability("goog:loggingPrefs", {"browser": "ALL"})
    return webdriver.Chrome(options=options)


def main():
    driver = build_driver()
    wait = WebDriverWait(driver, 20)
    try:
        driver.get(f"{BASE_URL}/task_select.php")
        driver.find_element(By.ID, "login_id").send_keys(LOGIN_ID)
        driver.find_element(By.ID, "password").send_keys(PASSWORD)
        driver.find_element(By.CSS_SELECTOR, "button[type='submit']").click()
        wait.until(lambda d: d.find_elements(By.ID, "login_id") == [])
        driver.get(f"{BASE_URL}/work_list_detail.php?report_id={REPORT_ID}")
        photo_desc = wait.until(lambda d: d.find_element(By.ID, "work-photo-description"))
        before = driver.execute_script(
            """
            const el = document.getElementById('work-photo-description');
            const rect = el.getBoundingClientRect();
            const x = rect.left + rect.width / 2;
            const y = rect.top + rect.height / 2;
            const topEl = document.elementFromPoint(x, y);
            return {
              activeId: document.activeElement ? document.activeElement.id : null,
              disabled: el.disabled,
              readOnly: el.readOnly,
              topId: topEl ? topEl.id : null,
              topTag: topEl ? topEl.tagName : null,
              topClass: topEl ? topEl.className : null
            };
            """
        )
        photo_desc.click()
        after_click = driver.execute_script(
            "return document.activeElement ? document.activeElement.id : null;"
        )
        photo_desc.clear()
        photo_desc.send_keys("keyboard-test")
        time.sleep(0.3)
        after_type = driver.execute_script(
            "return document.activeElement ? document.activeElement.id : null;"
        )

        body = driver.find_element(By.TAG_NAME, "body")
        body.send_keys(Keys.TAB)
        time.sleep(0.2)
        active_id = driver.execute_script("return document.activeElement ? document.activeElement.id : null;")

        print(json.dumps({
            "before": before,
            "active_after_click": after_click,
            "active_after_type": after_type,
            "typed_value": photo_desc.get_attribute("value"),
            "active_element_id_after_tab": active_id,
            "console_logs": driver.get_log("browser")
        }, ensure_ascii=False, indent=2))
        return 0
    finally:
        driver.quit()


if __name__ == "__main__":
    sys.exit(main())
