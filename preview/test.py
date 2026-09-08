import asyncio
from playwright.async_api import async_playwright

async def main():
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=False)
        context = await browser.new_context()
        page = await context.new_page()
        await page.goto("http://localhost:5500/", wait_until="networkidle")
        await page.wait_for_timeout(1500)
        # 点击"测试后端"按钮
        await page.click("text=测试 /gojs/api.php")
        await page.wait_for_timeout(2000)
        await page.click("text=system.info")
        await page.wait_for_timeout(1500)
        await page.click("text=开始采样")
        await page.wait_for_timeout(3000)
        await page.screenshot(path=r"C:\Users\Administrator\AppData\Roaming\TRAE SOLO CN\ModularData\ai-agent\work-mode-projects\6a9b275ab8a90ee8f2026b1c\Go.js-Lite\preview\preview.png", full_page=True)
        print("截图保存到 preview.png")
        await browser.close()

asyncio.run(main())