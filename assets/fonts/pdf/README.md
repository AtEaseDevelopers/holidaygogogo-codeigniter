# PDF font

`DroidSansFallback.ttf` supplies Chinese and Latin glyphs for Competitor Product
PDF downloads when `COMPETITOR_PDF_CJK_FONT` is unset or unavailable. A valid
configured TrueType font takes precedence.

The unmodified font comes from the Android Open Source Project's `android-4.4_r1`
release:
https://github.com/aosp-mirror/platform_frameworks_base/blob/android-4.4_r1/data/fonts/DroidSansFallback.ttf

The upstream Apache 2.0 license and copyright notice are preserved in
`DroidSansFallback-LICENSE.txt`.

`NotoSansSC-Regular.ttf` (400) is used for quotation body text.
`NotoSansSC-Bold.ttf` (700) also serves as a fallback for the optional
`NotoSansSC-Black.ttf` (900) heading face. The 400 and 900 faces are static instances
of the [upstream Noto Sans SC variable font](https://github.com/google/fonts/tree/main/ofl/notosanssc),
subset to the same characters as the bundled 700 face. All use the SIL Open
Font License in `NotoSansSC-LICENSE.txt`.
