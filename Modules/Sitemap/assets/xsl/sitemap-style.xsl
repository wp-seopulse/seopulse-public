<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
    xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
    xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
    
    <xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes"/>
    
    <xsl:template match="/">
        <html xmlns="http://www.w3.org/1999/xhtml">
            <head>
                <title>Sitemap XML - SEOPulse</title>
                <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
                <meta name="viewport" content="width=device-width, initial-scale=1.0" />
                <style type="text/css">
                    * {
                        margin: 0;
                        padding: 0;
                        box-sizing: border-box;
                    }
                    
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                        color: #333;
                        background: #f5f5f5;
                        line-height: 1.6;
                    }
                    
                    .header {
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        color: white;
                        padding: 40px 20px;
                        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                    }
                    
                    .header h1 {
                        margin: 0 0 10px 0;
                        font-size: 32px;
                        font-weight: 700;
                        text-shadow: 0 2px 4px rgba(0,0,0,0.2);
                    }
                    
                    .header p {
                        margin: 0;
                        opacity: 0.95;
                        font-size: 16px;
                    }
                    
                    .header-icon {
                        font-size: 48px;
                        margin-bottom: 10px;
                        display: inline-block;
                    }
                    
                    .container {
                        max-width: 1400px;
                        margin: 0 auto;
                        padding: 30px 20px;
                    }
                    
                    .stats {
                        background: white;
                        border-radius: 12px;
                        padding: 20px;
                        margin-bottom: 30px;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                        display: grid;
                        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                        gap: 20px;
                    }
                    
                    .stat-item {
                        text-align: center;
                        padding: 15px;
                        border-radius: 8px;
                        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                    }
                    
                    .stat-item strong {
                        display: block;
                        font-size: 36px;
                        color: #667eea;
                        margin-bottom: 8px;
                        font-weight: 700;
                    }
                    
                    .stat-item span {
                        color: #555;
                        font-size: 14px;
                        text-transform: uppercase;
                        letter-spacing: 1px;
                        font-weight: 600;
                    }
                    
                    table {
                        width: 100%;
                        border-collapse: collapse;
                        background: white;
                        border-radius: 12px;
                        overflow: hidden;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                    }
                    
                    thead {
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        color: white;
                    }
                    
                    th {
                        padding: 18px 15px;
                        text-align: left;
                        font-weight: 600;
                        font-size: 13px;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                    }
                    
                    td {
                        padding: 16px 15px;
                        border-bottom: 1px solid #f1f3f5;
                        font-size: 14px;
                    }
                    
                    tr:hover {
                        background-color: #f8f9fa;
                    }
                    
                    tr:last-child td {
                        border-bottom: none;
                    }
                    
                    .url-cell {
                        color: #667eea;
                        text-decoration: none;
                        word-break: break-all;
                        font-weight: 500;
                        transition: color 0.3s;
                    }
                    
                    .url-cell:hover {
                        color: #764ba2;
                        text-decoration: underline;
                    }
                    
                    .priority-high {
                        color: #28a745;
                        font-weight: 700;
                    }
                    
                    .priority-medium {
                        color: #ffc107;
                        font-weight: 700;
                    }
                    
                    .priority-low {
                        color: #6c757d;
                        font-weight: 600;
                    }
                    
                    .badge {
                        display: inline-block;
                        padding: 5px 10px;
                        border-radius: 20px;
                        font-size: 11px;
                        font-weight: 700;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                    }
                    
                    .badge-always {
                        background: #ff6b6b;
                        color: white;
                    }
                    
                    .badge-hourly {
                        background: #ff8c42;
                        color: white;
                    }
                    
                    .badge-daily {
                        background: #d4edda;
                        color: #155724;
                    }
                    
                    .badge-weekly {
                        background: #d1ecf1;
                        color: #0c5460;
                    }
                    
                    .badge-monthly {
                        background: #f8d7da;
                        color: #721c24;
                    }
                    
                    .badge-yearly {
                        background: #e2e3e5;
                        color: #383d41;
                    }
                    
                    .info-box {
                        background: linear-gradient(135deg, #e7f3ff 0%, #cfe7ff 100%);
                        border-left: 4px solid #2196F3;
                        padding: 20px;
                        margin: 20px 0;
                        border-radius: 8px;
                        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
                    }
                    
                    .info-box p {
                        margin: 0;
                        font-size: 15px;
                        color: #0c5460;
                        line-height: 1.6;
                    }
                    
                    .footer {
                        text-align: center;
                        padding: 40px 20px;
                        color: #666;
                        font-size: 14px;
                    }
                    
                    .footer strong {
                        color: #667eea;
                        font-weight: 700;
                    }
                    
                    .images-count {
                        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
                        color: white;
                        padding: 4px 10px;
                        border-radius: 20px;
                        font-size: 11px;
                        font-weight: 700;
                        margin-left: 10px;
                        display: inline-block;
                    }
                    
                    .news-badge {
                        background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
                        color: white;
                        padding: 3px 8px;
                        border-radius: 4px;
                        font-size: 10px;
                        font-weight: 700;
                        margin-left: 8px;
                        text-transform: uppercase;
                    }
                    
                    @media (max-width: 768px) {
                        .header h1 {
                            font-size: 24px;
                        }
                        
                        .stats {
                            grid-template-columns: 1fr;
                        }
                        
                        table {
                            font-size: 12px;
                        }
                        
                        th, td {
                            padding: 10px;
                        }
                        
                        .stat-item strong {
                            font-size: 28px;
                        }
                    }
                    
                    .legend {
                        background: white;
                        border-radius: 8px;
                        padding: 15px;
                        margin: 20px 0;
                        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
                    }
                    
                    .legend h3 {
                        font-size: 14px;
                        color: #666;
                        margin-bottom: 10px;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                    }
                    
                    .legend-items {
                        display: flex;
                        gap: 15px;
                        flex-wrap: wrap;
                    }
                    
                    .legend-item {
                        font-size: 12px;
                        color: #666;
                    }
                </style>
            </head>
            <body>
                <div class="header">
                    <div class="container">
                        <div class="header-icon">📄</div>
                        <h1>Sitemap XML</h1>
                        <p>Généré automatiquement par SEOPulse - Compatible Google, Bing et tous les moteurs de recherche</p>
                    </div>
                </div>
                
                <div class="container">
                    <xsl:choose>
                        <!-- Sitemap Index -->
                        <xsl:when test="sitemap:sitemapindex">
                            <div class="stats">
                                <div class="stat-item">
                                    <strong><xsl:value-of select="count(sitemap:sitemapindex/sitemap:sitemap)"/></strong>
                                    <span>Sitemaps</span>
                                </div>
                            </div>
                            
                            <div class="info-box">
                                <p>ℹ️ <strong>Index de sitemaps</strong> - Ce fichier regroupe tous les sitemaps de votre site. Cliquez sur les URLs ci-dessous pour voir le contenu détaillé de chaque sitemap.</p>
                            </div>
                            
                            <table>
                                <thead>
                                    <tr>
                                        <th>Sitemap</th>
                                        <th style="width: 200px;">Dernière modification</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <xsl:for-each select="sitemap:sitemapindex/sitemap:sitemap">
                                        <tr>
                                            <td>
                                                <a href="{sitemap:loc}" class="url-cell">
                                                    <xsl:value-of select="sitemap:loc"/>
                                                </a>
                                            </td>
                                            <td>
                                                <xsl:value-of select="sitemap:lastmod"/>
                                            </td>
                                        </tr>
                                    </xsl:for-each>
                                </tbody>
                            </table>
                        </xsl:when>
                        
                        <!-- Sitemap standard -->
                        <xsl:otherwise>
                            <div class="stats">
                                <div class="stat-item">
                                    <strong><xsl:value-of select="count(sitemap:urlset/sitemap:url)"/></strong>
                                    <span>URLs</span>
                                </div>
                                <xsl:if test="count(sitemap:urlset/sitemap:url/image:image) &gt; 0">
                                    <div class="stat-item">
                                        <strong><xsl:value-of select="count(sitemap:urlset/sitemap:url/image:image)"/></strong>
                                        <span>Images</span>
                                    </div>
                                </xsl:if>
                                <xsl:if test="count(sitemap:urlset/sitemap:url/news:news) &gt; 0">
                                    <div class="stat-item">
                                        <strong><xsl:value-of select="count(sitemap:urlset/sitemap:url/news:news)"/></strong>
                                        <span>Articles News</span>
                                    </div>
                                </xsl:if>
                            </div>
                            
                            <xsl:if test="count(sitemap:urlset/sitemap:url/news:news) &gt; 0">
                                <div class="info-box">
                                    <p>📰 <strong>Google News Sitemap</strong> - Ce sitemap contient les articles publiés dans les 48 dernières heures, optimisés pour Google Actualités.</p>
                                </div>
                            </xsl:if>
                            
                            <div class="legend">
                                <h3>Légende des priorités</h3>
                                <div class="legend-items">
                                    <div class="legend-item"><span class="priority-high">●</span> Élevée (0.8-1.0)</div>
                                    <div class="legend-item"><span class="priority-medium">●</span> Moyenne (0.5-0.7)</div>
                                    <div class="legend-item"><span class="priority-low">●</span> Basse (&lt;0.5)</div>
                                </div>
                            </div>
                            
                            <table>
                                <thead>
                                    <tr>
                                        <th>URL</th>
                                        <th style="width: 150px;">Dernière modif.</th>
                                        <th style="width: 120px;">Fréquence</th>
                                        <th style="width: 100px;">Priorité</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <xsl:for-each select="sitemap:urlset/sitemap:url">
                                        <tr>
                                            <td>
                                                <a href="{sitemap:loc}" class="url-cell" target="_blank">
                                                    <xsl:value-of select="sitemap:loc"/>
                                                </a>
                                                <xsl:if test="count(image:image) &gt; 0">
                                                    <span class="images-count">
                                                        🖼️ <xsl:value-of select="count(image:image)"/>
                                                    </span>
                                                </xsl:if>
                                                <xsl:if test="news:news">
                                                    <span class="news-badge">News</span>
                                                </xsl:if>
                                            </td>
                                            <td>
                                                <xsl:value-of select="sitemap:lastmod"/>
                                            </td>
                                            <td>
                                                <xsl:choose>
                                                    <xsl:when test="sitemap:changefreq = 'always'">
                                                        <span class="badge badge-always">Toujours</span>
                                                    </xsl:when>
                                                    <xsl:when test="sitemap:changefreq = 'hourly'">
                                                        <span class="badge badge-hourly">Horaire</span>
                                                    </xsl:when>
                                                    <xsl:when test="sitemap:changefreq = 'daily'">
                                                        <span class="badge badge-daily">Quotidien</span>
                                                    </xsl:when>
                                                    <xsl:when test="sitemap:changefreq = 'weekly'">
                                                        <span class="badge badge-weekly">Hebdo</span>
                                                    </xsl:when>
                                                    <xsl:when test="sitemap:changefreq = 'monthly'">
                                                        <span class="badge badge-monthly">Mensuel</span>
                                                    </xsl:when>
                                                    <xsl:when test="sitemap:changefreq = 'yearly'">
                                                        <span class="badge badge-yearly">Annuel</span>
                                                    </xsl:when>
                                                    <xsl:otherwise>
                                                        <xsl:value-of select="sitemap:changefreq"/>
                                                    </xsl:otherwise>
                                                </xsl:choose>
                                            </td>
                                            <td>
                                                <xsl:choose>
                                                    <xsl:when test="number(sitemap:priority) &gt;= 0.8">
                                                        <span class="priority-high">
                                                            <xsl:value-of select="sitemap:priority"/>
                                                        </span>
                                                    </xsl:when>
                                                    <xsl:when test="number(sitemap:priority) &gt;= 0.5">
                                                        <span class="priority-medium">
                                                            <xsl:value-of select="sitemap:priority"/>
                                                        </span>
                                                    </xsl:when>
                                                    <xsl:otherwise>
                                                        <span class="priority-low">
                                                            <xsl:value-of select="sitemap:priority"/>
                                                        </span>
                                                    </xsl:otherwise>
                                                </xsl:choose>
                                            </td>
                                        </tr>
                                    </xsl:for-each>
                                </tbody>
                            </table>
                        </xsl:otherwise>
                    </xsl:choose>
                </div>
                
                <div class="footer">
                    <p>
                        Généré par <strong>SEOPulse</strong><br/>
                        Compatible avec les standards <strong>Google</strong>, <strong>Bing</strong> et <strong>Sitemaps.org</strong>
                    </p>
                </div>
            </body>
        </html>
    </xsl:template>
</xsl:stylesheet>