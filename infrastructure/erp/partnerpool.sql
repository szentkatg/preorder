-- Run on the SQL Server that has the [U4 LIVE] linked server configured.
-- The only write is a session-local #temporary table; ERP tables are read only.
SET NOCOUNT ON;
DROP TABLE IF EXISTS #addresses;

SELECT * INTO #addresses
FROM OPENQUERY([U4 LIVE], '
    SELECT p.partnid, pa.tel, pa.addrid, pa.name, pa.countryid,
           pa.postcode, pa.add01, pa.add02, pa.add03, pa.add04,
           pa.add05, pa.add06, pa.email, pa.def, pa.type
    FROM HTO_ERP_LIVE.dbo.ols_partnaddr pa WITH (NOLOCK)
    LEFT JOIN HTO_ERP_LIVE.dbo.ols_partner p WITH (NOLOCK) ON p.partnid = pa.partnid
    WHERE (p.type & 126) > 0
');

SELECT
    sp.partncode AS erp_partner_code,
    sp.name AS partner_name,
    sp.sname AS short_name,
    CASE
        -- Chaining & 62 & 64 always yields 0; test the two masks separately.
        WHEN (sp.type & 62) > 0 AND (sp.type & 64) > 0 THEN 'both'
        WHEN (sp.type & 62) > 0 THEN 'customer'
        WHEN (sp.type & 64) > 0 THEN 'supplier'
    END AS partner_type,
    cp.wsemail AS partner_email,
    pa.tel AS partner_phone,
    rep.ref1 AS sales_rep_erp_partner_code,
    1 AS partner_active,
    pa.addrid AS addrid,
    pa.name AS address_name,
    pa.countryid AS country,
    pa.postcode AS zip,
    pa.add01 AS city,
    LTRIM(RTRIM(CONCAT(pa.add02, ' ', ISNULL(pa.add03, ''), ' ',
        ISNULL(pa.add04, ''), ' ', ISNULL(pa.add05, ''), ' ', ISNULL(pa.add06, '')))) AS street,
    (SELECT TOP 1 CONCAT(em.lastname, ' ', em.firstname)
        FROM [U4 LIVE].[HTO_ERP_LIVE].[dbo].[ols_employee] em
        WHERE em.partnid = sp.partnid AND em.delstat = 0
        ORDER BY em.empid) AS contact_name,
    pa.email AS address_email,
    pa.tel AS address_phone,
    1 AS address_active,
    pa.def AS [default],
    CASE WHEN cp.invlngid = 'hu-HU' THEN 'HU' ELSE 'EN' END AS [Language],
    spc.curid AS [Currency],
    (SELECT COALESCE('[' + STRING_AGG('"' + STRING_ESCAPE(CONVERT(nvarchar(max), cpb.brandid), 'json') + '"', ',') + ']', '[]')
        FROM [U4 LIVE].[HTO_ERP_LIVE].[dbo].[olc_partnerbrand] cpb WHERE cpb.partnid = sp.partnid) AS [brands],
    '["T","S","B"]' AS [ordersheets],
    CASE
        WHEN cp.invlngid = 'hu-HU' AND (sp.type & 58) > 0 AND spc.curid = 'HUF' THEN 'Wholesale_HU'
        WHEN cp.invlngid <> 'hu-HU' AND (sp.type & 58) > 0 AND spc.curid = 'EUR' THEN 'Wholesale_EU'
        WHEN cp.invlngid <> 'hu-HU' AND (sp.type & 4) > 0 AND spc.curid = 'EUR' THEN 'Distributor_EUR'
    END AS [pricelist],
    tl.address_types AS [address type]
FROM [U4 LIVE].[HTO_ERP_LIVE].[dbo].[ols_partner] sp
LEFT JOIN #addresses pa ON pa.partnid = sp.partnid
LEFT JOIN [U4 LIVE].[HTO_ERP_LIVE].[dbo].[olc_partner] cp ON cp.partnid = sp.partnid
LEFT JOIN [U4 LIVE].[HTO_ERP_LIVE].[dbo].[ols_employee] rep ON rep.empid = cp.regreprempid
LEFT JOIN [U4 LIVE].[HTO_ERP_LIVE].[dbo].[ols_partncmp] spc ON spc.partnid = sp.partnid AND spc.cmpid = 3
LEFT JOIN (
    SELECT address_flags.type,
        STRING_AGG(CONVERT(nvarchar(max), t.name), ',') WITHIN GROUP (ORDER BY t.seqno) AS address_types
    FROM (SELECT DISTINCT type FROM #addresses) address_flags
    INNER JOIN [U4 LIVE].[HTO_ERP_LIVE].[dbo].[ols_typeline] t
        ON (address_flags.type & t.value) = t.value AND t.typegrpid = 2 AND t.delstat = 0
    GROUP BY address_flags.type
) tl ON pa.type = tl.type
WHERE (sp.type & 126) > 0 AND sp.delstat = 0;

-- If countryid is an internal ID, resolve it to an ISO alpha-2 code above,
-- or configure country_map in the sync config. No guessed mapping is applied.
-- address_active=1 and pa.def keep the semantics of the supplied ERP query.
-- If ERP addresses have deletion/activity flags, expose those instead of 1.
