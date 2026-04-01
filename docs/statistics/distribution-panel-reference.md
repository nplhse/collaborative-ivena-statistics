# Distribution Panel Referenz

## Beispiel-SQL (ohne Gruppierung)

```sql
SELECT urgency_code AS dimension_key, NULL AS group_key, COUNT(*) AS value
FROM allocation_stats_projection
WHERE created_at >= :date_from_default
GROUP BY urgency_code
ORDER BY urgency_code
```

## Beispiel-SQL (mit Gruppierung)

```sql
SELECT urgency_code AS dimension_key, hospital_tier_code AS group_key, COUNT(*) AS value
FROM allocation_stats_projection
WHERE created_at >= :date_from_default
  AND hospital_tier_code IN (:hospital_tier_code_0, :hospital_tier_code_1)
GROUP BY urgency_code, hospital_tier_code
ORDER BY urgency_code, hospital_tier_code
```

## Neutrales Transformer-Format

```json
{
  "labels": ["Rot", "Gelb"],
  "series": [
    {
      "name": "Grundversorger",
      "values": [10, 20],
      "percentages": [50, 50]
    }
  ]
}
```

## Tabellenbeispiel (unterhalb Chart)

```json
[
  {
    "dimensionLabel": "Rot",
    "groupLabel": "Grundversorger",
    "value": 10,
    "percent": 50
  }
]
```
