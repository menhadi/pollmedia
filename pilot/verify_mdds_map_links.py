"""Incremental SOI/LGD links supported by original Census MDDS crosswalk rows.

This resolver never edits source identifiers or geometry. A source-specific
subdistrict identifier is retained verbatim; current parent names must match
the explicit LGD ancestry, and Census geography must match the MDDS directory.
"""
import json

from build_village_display_links import identity_code, normalize_name
from build_urban_map_links import census_identity, civic_name


def mdds_identity(row):
    return tuple(identity_code(value) for value in row[5:9])


def qualify(source, directory_rows, mdds_rows, published_rows, administrative_evidence=None):
    state = identity_code(source.get('State_LGD'))
    code = identity_code(source.get('Vill_LGD'))
    if not state or not code or source.get('Vill_Cat', '').casefold() != 'rural':
        return None
    # No suffix stripping, fuzzy names, inferred merger, or urban membership.
    villages = {row['lgd']: row for row in directory_rows
                if row['state'] == state and code in {
                    identity_code(row['lgd']), identity_code(row['census2011'])}}
    if len(villages) != 1:
        return None
    village = next(iter(villages.values()))
    if (normalize_name(source.get('District', '')) != normalize_name(village['district'])
            or normalize_name(source.get('Sub_dist', '')) != normalize_name(village['subdistrict'])):
        return None
    census_code = identity_code(village['census2011'])
    crosswalk = [entry for entry in mdds_rows
                 if mdds_identity(entry['row'])[0] == state
                 and mdds_identity(entry['row'])[3] == census_code]
    identities = {mdds_identity(entry['row']) for entry in crosswalk}
    if len(identities) != 1 or not all(next(iter(identities))):
        return None
    identity = next(iter(identities))
    # Curated, page-reviewed official schedules supplement a specific source
    # record. They never rewrite its identifiers or establish boundary equality.
    evidence = administrative_evidence or {}
    supported = (evidence.get('status') == 'issued'
                 and evidence.get('source_attributes') == source
                 and evidence.get('lgd') == village
                 and evidence.get('census_identity') == list(identity)
                 and evidence.get('whole_settlement') is True
                 and evidence.get('page_reviewed') is True
                 and len(evidence.get('sha256', '')) == 64
                 and evidence.get('url', '').startswith('https://')
                 and evidence.get('page') and evidence.get('schedule_row'))
    if identity_code(source.get('Dist_LGD')) not in {identity[1], identity_code(village['district_code'])}:
        return None
    # The entire source subdistrict code is checked when it is numeric.
    # Non-numeric source codes rely on both exact current LGD parent names.
    subdistrict = identity_code(source.get('Subdis_LGD'))
    parent_confirmed = (supported and evidence.get('relation') == 'coded_current_parent'
                        and identity_code(evidence.get('official_settlement_code')) == census_code)
    if subdistrict and subdistrict not in {identity[2], identity_code(village['subdistrict_code'])} and not parent_confirmed:
        return None
    named = [entry for entry in crosswalk if normalize_name(source.get('Vill_name', '')) in {
        normalize_name(entry['row'][4] or ''), normalize_name(entry['row'][9] or '')}]
    if not named:
        name_confirmed = (supported and evidence.get('relation') == 'scheduled_current_name'
                          and normalize_name(evidence.get('official_name', '')) == normalize_name(source.get('Vill_name', '')))
        if not name_confirmed:
            return None
        named = crosswalk
    records = [row for row in published_rows if identity_code(row['state_code']) == state
               and identity_code(json.loads(row['geography']).get('Town/Village')) == census_code]
    if len({(row['level'], row['residence'], census_identity(row)) for row in records}) != 1:
        return None
    if not records or census_identity(records[0]) != identity:
        return None
    record = records[0]  # Input uses the existing newest-published edition order.
    if (record['level'], record['residence']) not in {('VILLAGE', 'Rural'), ('TOWN', 'Urban')}:
        return None
    record_name = normalize_name(record['name'])
    official_names = {normalize_name(entry['row'][9] or '') for entry in crosswalk}
    if record_name not in official_names:
        stem, designation = civic_name(record['name'])
        if record['level'] != 'TOWN' or designation != 'CT' or stem not in official_names:
            return None
    return {'lgd': village, 'census': record, 'mdds': named,
            'administrative_evidence': evidence if supported else None,
            'basis': ('Original SOI village code; reviewed official administrative schedule and Census MDDS crosswalk; '
                      if supported else 'Original SOI village code and exact Census MDDS 2001/2011 name crosswalk; ') +
                     'unique explicit LGD Census crosswalk with exact current district/subdistrict names; '
                     'full published Census 2011 geography verified; original source subdistrict code '
                     'retained verbatim; dated record values, boundary edition correspondence unverified'}
