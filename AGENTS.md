# Project print layout conventions

For new or modified print views in this project, follow the user's established layout requirements:

- Printing uses the browser print dialog with **no margins**. Use `@page { size: A4 portrait; margin: 0; }` when the document is A4 portrait; set other paper orientation only when explicitly requested.
- Create all printable page margins with CSS padding on the page container, not with `@page` margins.
- Reserve at least **20 mm of top padding** for binding.
- Draw a visible box border around the document content inside the padded page.
- Put the company logo and **주식회사 현대기전** together at the bottom right **outside the document box**. Use an existing logo asset, such as `CSM/assets/policy-logo.png`, and keep the logo adjacent to the name.
- Check the browser print preview with margins set to none. Keep controls and navigation out of the printed page.
