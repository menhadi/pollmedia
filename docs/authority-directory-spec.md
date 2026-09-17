# Representatives and administrative authorities

Status: confirmed requirement and proposed design; implementation pending.

## Purpose

Each relevant Pollmedia place page should show its elected representatives and administrative/service authorities. The directory must accommodate frequent officer transfers, appointments, vacancies, acting appointments and electoral changes. A change accepted in the shared directory should update every linked place page and issue view.

## Coverage

| Place or service | Roles to support where applicable |
|---|---|
| Parliamentary constituency | MP |
| Assembly constituency | MLA |
| State / other electoral jurisdictions | Other elected representatives through their actual constituencies or selection structure, not assumed AC/PC membership |
| District / subdivision / tehsil | District Magistrate or equivalent, relevant district department heads, subdivision and tehsil officers |
| Block | Block administration and relevant service departments |
| Gram Panchayat / rural local bodies | Pradhan/Sarpanch and other applicable elected members; administrative functionaries |
| Municipality / municipal corporation / ward | Mayor/chairperson, councillor and relevant municipal officers |
| Service jurisdiction | Responsible water, roads, health, education, electricity and other agencies, even where their service boundaries differ from administrative boundaries |

Specific titles and powers vary by jurisdiction. Confirm the applicable structure from authoritative sources rather than hard-coding a single national hierarchy.

## Stable office, changing person

Separate these entities:

- **Organization:** department, municipality, district administration or other body.
- **Office/role:** a position such as district magistrate or constituency MLA.
- **Jurisdiction:** the geography or service area for that office, with effective dates.
- **Person:** an individual, without unnecessary private information.
- **Tenure/assignment:** the person holding a role, with start/end dates when known, appointment status, official evidence and verification dates.
- **Official contact:** published office contact channel and its verification date; prefer durable office channels over person-specific ones.
- **Responsibility mapping:** issue category and service/asset ownership linked to the office or organization.

Allow a person to hold multiple assignments and a role to have acting/additional-charge arrangements. Represent vacancies, disputed/conflicting records and unknown dates explicitly. Never invent a tenure start from the date a webpage was crawled.

## Data refresh

Discover and validate relevant official sources before writing each connector: legislature/member directories for current representatives, election results for electoral events, district/department directories, appointment/transfer orders and local-body sources for other roles. These are source categories to research, not established integrations.

Proposed initial schedule: daily checks for active district/service officer directories and current representative directories, with source-specific rate limits and backoff. Increase checks around a known election or official appointment event only when supported. Use documented notification feeds where available; otherwise check for changes. Scheduling begins in the deployed application, not as a Codex reminder.

For every source:

1. Track the official URL, source publication/effective date, retrieval time, last successful check and content fingerprint.
2. Compare structured office assignments, not entire-page incidental changes.
3. Validate role, jurisdiction, identity, effective date and contact changes. Do not use a name-only match to merge people.
4. Activate high-confidence changes under source-specific rules. Queue conflicts, OCR uncertainty, ambiguous replacements and unexplained removals for review.
5. End the previous assignment only when replacement/end evidence supports doing so; retain the source and historical record.
6. Refresh all affected place-page and issue-view caches after an accepted update.

Official publication may lag actual appointments. Pollmedia should promise periodic checks and visible freshness, not instantaneous or infallible personnel updates. A failed fetch or a name disappearing from one page must not automatically establish a vacancy or delete the officeholder.

## Public display

Representative/authority cards show role, person, jurisdiction, assignment status, published official office contacts, source link and last verification date. Show appointment/tenure dates only when verified.

If information becomes stale, retain it as **last verified** with the date and a link to the official directory. Conflicting or incomplete evidence must not be presented as a confidently current appointment. Prefer the stable office contact while a person's current assignment is unresolved.

Historical views resolve the person serving at the relevant date. A past response remains attributed to its actual author/office and date after staff changes.

## Public profiles and biography links

Confirmed requirement: representative and officer cards link to public profiles where available, including official biographies, Wikipedia and other reliable public profiles.

- Prioritize official legislature, government, department or local-body biography pages. Add Wikipedia as a clearly labelled supplementary source where the person's identity is confirmed.
- Other links may include an established institutional biography, the person's verified public website or verified public social profile. Identify the publisher/type visibly; do not imply official endorsement of third-party content.
- Store profile links against the person, separately from office/jurisdiction contacts. When the officeholder changes, the current card resolves the new person's profile links; historical cards retain the prior person's links.
- Confirm identity using multiple attributes such as office, constituency, organization and biographical details. Do not attach a profile based only on a matching name. Handle Wikipedia disambiguation pages and redirects explicitly.
- Store URL, source type, language, verification evidence, last checked date and link status. Prefer stable identifiers such as a verified Wikidata entity ID when useful, without treating Wikidata as evidence of current officeholding.
- Check links periodically and flag broken, redirected-to-another-person or conflicting profiles. A missing Wikipedia page does not prevent the authority card from appearing; show available official links instead.
- Public profile links supplement the directory. Current appointments and jurisdiction assignments must still come from verified official evidence.
- Link out by default. Any copied biography text or images require their own attribution and reuse checks; a profile link does not authorize copying the entire page.

Suggested controls: **Official profile**, **Wikipedia**, **Public website** and **Verified social profile**, showing only links actually available and verified for that person.

## Linking issues and updates

Link each issue to the responsible office/department and jurisdiction; display the current verified officeholder dynamically. Show the area's representatives separately from administrative responsibility. Department ownership may depend on the specific asset or service, not merely the issue category.

Changing an officeholder updates the directory and linked views. It does not send unsolicited email, SMS or other external messages. Any future notification/delivery workflow needs a separately defined channel, purpose and authorization. Pending issues remain linked to the stable office; preserve prior routing and response history.

## Acceptance checks

- A verified replacement updates all linked current views without changing historical attribution.
- Acting/additional-charge assignments and vacancies display correctly.
- Transfers to another jurisdiction do not move or erase the old office's issues.
- Failed or partial source refreshes preserve the last verified record and show freshness accurately.
- Conflicting sources and uncertain effective dates enter review rather than silently overwriting data.
- Published contacts and person identities are not merged by name alone.
- Election results alone are not treated as proof of uninterrupted current officeholding.
