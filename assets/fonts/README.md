# PDF font provenance

Only IBM Plex Sans Arabic Regular and Bold are bundled, unmodified, under the SIL Open Font
License 1.1 in [OFL.txt](OFL.txt). Copyright 2017 IBM Corp.; reserved font name Plex.

Source: [Google Fonts, pinned commit 702964cf46bd1bf33d0745d24bbaac33edf8b8af](https://github.com/google/fonts/tree/702964cf46bd1bf33d0745d24bbaac33edf8b8af/ofl/ibmplexsansarabic),
the distribution of [IBM Plex](https://github.com/IBM/plex).

SHA-256:

| File | SHA-256 |
| --- | --- |
| IBMPlexSansArabic-Regular.ttf | 6f611412270a132bbac838da9259d4c68569b4175f3b3b8fa3fa36a30b56dab9 |
| IBMPlexSansArabic-Bold.ttf | 691e0c891a38637ae6bbdb69700f8042cb0724a137bee615068ffdb92244f61f |
| OFL.txt | 7e6b2818edbd8f6a01ae80641cc8f16a51080d08fb4e532be3a0b6f74adb07da |

PdfFonts configures this family explicitly with OpenType layout (`useOTL = 0xFF`) and
`useKashida = 0`. Arabic and Latin use the same family, so automatic language-to-font switching,
substitution fonts and Adobe CJK are disabled. No font is fetched at runtime.
