<?xml version="1.0" encoding="UTF-8"?>
<!-- How darshansaathi.com's sitemaps look in a browser. Search engines read the XML and ignore this. -->
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
    exclude-result-prefixes="sm image">
<xsl:output method="html" encoding="UTF-8" indent="yes"/>
<xsl:template match="/">
<html>
<head>
    <meta charset="utf-8"/>
    <meta name="robots" content="noindex"/>
    <title>Sitemap · Darshan Saathi</title>
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; margin: 0; background: #f6efe3; color: #3e2723; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }
        h1 { font-family: Georgia, serif; margin: 0 0 4px; }
        p { color: #7a6a60; margin: 0 0 16px; }
        table { width: 100%; border-collapse: collapse; background: #fffdf9; border: 1px solid #e7dccb; border-radius: 12px; overflow: hidden; font-size: .9rem; }
        th, td { text-align: left; padding: 8px 10px; border-top: 1px solid #efe5d6; vertical-align: top; }
        th { background: #3e2723; color: #fff; font-weight: 600; }
        td.n { color: #7a6a60; white-space: nowrap; }
        a { color: #9b1b30; word-break: break-all; }
    </style>
</head>
<body><div class="wrap">
    <xsl:choose>
        <xsl:when test="sm:sitemapindex">
            <h1>Sitemap index</h1>
            <p><xsl:value-of select="count(sm:sitemapindex/sm:sitemap)"/> sitemaps. This is the file to submit to Google Search Console.</p>
            <table>
                <tr><th>Sitemap</th><th>Last changed</th></tr>
                <xsl:for-each select="sm:sitemapindex/sm:sitemap">
                    <tr><td><a href="{sm:loc}"><xsl:value-of select="sm:loc"/></a></td><td class="n"><xsl:value-of select="substring(sm:lastmod, 1, 10)"/></td></tr>
                </xsl:for-each>
            </table>
        </xsl:when>
        <xsl:otherwise>
            <h1>Sitemap</h1>
            <p><xsl:value-of select="count(sm:urlset/sm:url)"/> pages, <xsl:value-of select="count(sm:urlset/sm:url/image:image)"/> images.</p>
            <table>
                <tr><th>Page</th><th>Images</th><th>Last changed</th></tr>
                <xsl:for-each select="sm:urlset/sm:url">
                    <tr><td><a href="{sm:loc}"><xsl:value-of select="sm:loc"/></a></td><td class="n"><xsl:value-of select="count(image:image)"/></td><td class="n"><xsl:value-of select="substring(sm:lastmod, 1, 10)"/></td></tr>
                </xsl:for-each>
            </table>
        </xsl:otherwise>
    </xsl:choose>
</div></body>
</html>
</xsl:template>
</xsl:stylesheet>
