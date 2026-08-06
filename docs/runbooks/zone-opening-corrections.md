# Runbook: correcting what a zone opening refused

Opening a zone runs a completeness gate: a bookable accommodation that no resolver can
name is **not imported** (ADR-049 §3). This runbook covers the loop that lets an operator
fix the refusals worth fixing — reading the report, writing the corrections, importing
them — and the one limitation the design accepts.

It is deliberately narrow. The zone-opening procedure itself lives in
[valhalla-routing-graph.md](valhalla-routing-graph.md) for the routing half and in the
zone-opening runbook for the reference half; this file is only about the corrections.

## 1. Read the report

Every opening and re-opening writes, per zone:

```text
.docker/osm/data/zones/<zone>/rejected.tsv                  # OSM refusals
.docker/osm/data/zones/<zone>/rejected-datatourisme.tsv     # flux refusals
```

Tab-separated with a header:

| Column | Meaning |
|---|---|
| `source` | `osm` or `datatourisme` |
| `source_id` | `N/123` for OSM (type + id), the flux id otherwise |
| `category` | `hotel`, `camp_site`, … |
| `lat`, `lon` | where it is |
| `commune` | resolved offline from the imported boundaries |
| `reason` | why the gate refused it |
| `cycle_route_m` | metres to the nearest signed cycle route |
| `tags` | the raw OSM tags, for context |

**The file is sorted by `cycle_route_m`, nearest first, and that is the point.** You work
the thirty refusals that border a véloroute, not the three thousand lost in open country.
The human effort is bounded by the ordering, not by your discipline — stop whenever the
distances stop being interesting.

The console also prints the counts: names resolved, how many came from the curated flux,
entries refused, and the refusals broken down by motive.

## 2. Read the size of the file as a signal about the code

A long `rejected.tsv` is **not** a backlog of manual work. It means the resolvers are weak.
The first of them — projecting tags the database already holds — should absorb most of the
volume, so a long file says it did not. The command says so itself when refusals outnumber
resolutions.

Before working through a large file, check whether the fix belongs in
`provisioner/src/NameResolver.php` instead. Manual corrections do not scale and are lost on
a rebuild (§5); a resolver improvement is retroactive — bump `NameResolver::VERSION` and the
next opening retries every entry that version refused, and only those.

## 3. Write the corrections

Copy the report, keep the rows you want to fix, and give each one a name:

```bash
cd .docker/osm/data/zones/bretagne
cp rejected.tsv override.tsv
# edit override.tsv: keep the useful rows, fill in `name`
```

The required columns are the first six, in order, and the header row is skipped so you can
edit the report in place:

<!-- markdownlint-disable MD010 -- the tabs below are the file format, not indentation -->

```text
source	source_id	category	lat	lon	name
osm	N/1234567	camp_site	48.512345	-2.765432	Camping municipal du Moulin
```

<!-- markdownlint-enable MD010 -->

Three optional columns may follow, in this order: `website`, `description`,
`opening_hours`. Leave them empty or omit them entirely.

A malformed file is refused **whole**, naming the offending line. The parse completes before
any statement runs and the insert is one transaction, so there is no such thing as a
half-applied override.

## 4. Import them

```bash
make provision-override bretagne
# or, for a file elsewhere:
make provision-override bretagne /data/zones/bretagne/override.tsv
```

What happens, and what does not:

- The rows are inserted into `osm.accommodations` / `tourism.accommodations` with the zone
  that corrected them and `ON CONFLICT DO NOTHING`. **An override adds what the gate refused;
  it never rewrites a row already imported** (ADR-049 §5). If you need to change a value that
  is already live, this file cannot do it — deliberately, because the alternative is a
  mechanism that can silently overwrite the sources.
- Re-opening the zone will not re-analyse them: the enrichment cache holds a negative entry
  for each, and the promotion's identity anti-join sees the row present. They also stop
  appearing in `rejected.tsv`, so you are not asked to fix them twice.

There is no endpoint, no authentication, no interface and no versioning. It is data, it does
not belong in git, and it does not justify a table.

## 5. The limitation, stated rather than discovered

**Nothing stores your file.** The corrections live only in the live tables, so a database
rebuilt from scratch loses every one whose `override.tsv` you did not keep.

That is the accepted price of "no table, no versioning". Two consequences:

- **Keep the file**, somewhere that survives the database. The command reminds you.
- **Prefer a resolver fix** whenever a correction generalises. A pattern fixed in
  `NameResolver` applies to every zone, past and future, and survives any rebuild; a hand
  correction applies to one row until someone drops the database.

## 6. Check nothing reached git

The report and override files live under `.docker/osm/data/`, which is gitignored in full
(`.docker/osm/data/*`). If you moved a file elsewhere in the tree, check before committing:

```bash
git status --short
```
