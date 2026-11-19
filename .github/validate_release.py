"""Check repository metadata and select release notes for a pushed v* tag."""

import json
import os
from pathlib import Path
import re

import yaml


def require(condition, message):
    if not condition:
        raise SystemExit(message)


cff = yaml.safe_load(Path("CITATION.cff").read_text(encoding="utf-8"))
zenodo = json.loads(Path(".zenodo.json").read_text(encoding="utf-8"))
repo_url = "https://github.com/" + os.environ["GITHUB_REPOSITORY"]

require(not {"version", "date-released"} & cff.keys(), "Remove release dates and versions from CITATION.cff.")
require(not {"version", "publication_date", "doi"} & zenodo.keys(), "Zenodo assigns release-specific metadata.")
require(cff["cff-version"] == "1.2.0" and cff["type"] == "software", "Expected software in CFF 1.2.0 format.")
require(cff["url"] == cff["repository-code"] == repo_url, "Citation URLs must match the GitHub repository.")
require(zenodo["upload_type"] == "software" and zenodo["access_right"] == "open"
        and zenodo["language"] == "eng", "Expected open software with English metadata.")
for z_key, c_key in (("title", "title"), ("description", "abstract")):
    require(zenodo[z_key] == cff[c_key], f"Zenodo {z_key} differs from CFF {c_key}.")
require(set(zenodo["keywords"]) == set(cff["keywords"]), "Keywords differ.")
require(zenodo["license"].lower() == cff["license"].lower(), "Licenses differ.")
creators = [
    {"name": f"{a['family-names']}, {a['given-names']}", "affiliation": a["affiliation"],
     "orcid": a["orcid"].removeprefix("https://orcid.org/")}
    for a in cff["authors"]
]
require(zenodo["creators"] == creators, "Zenodo creators must match the citation authors.")
require("REPLACE_ME" not in str(zenodo) + str(cff), "Replace metadata placeholders.")
require(all(item["identifier"].rstrip("/") != repo_url for item in zenodo.get("related_identifiers", [])),
        "Zenodo already links the GitHub repository.")

tag = os.environ["GITHUB_REF_NAME"]
if tag == "vPrep":
    notes = "Preparatory release for initializing the Zenodo archive."
elif re.fullmatch(r"v[0-9]+\.[0-9]+\.[0-9]+", tag):
    doi = cff.get("doi", "")
    require(re.fullmatch(r"10\.5281/zenodo\.\d+", doi), "Expected a Zenodo concept DOI in CITATION.cff.")
    badge = f"[![DOI](https://zenodo.org/badge/DOI/{doi}.svg)](https://doi.org/{doi})"
    for readme in [Path("README.md"), *Path(".").glob("README.*.md")]:
        require(badge in readme.read_text(encoding="utf-8"), f"Check the DOI badge in {readme}.")

    row = re.compile(r"^\|\s*v?" + re.escape(tag[1:]) + r"\s*\|(.+)\|\s*$", re.MULTILINE)
    entries = row.findall(Path("CHANGELOG.md").read_text(encoding="utf-8"))
    require(len(entries) == 1 and entries[0].strip(), f"Expected one non-empty changelog entry for {tag}.")
    notes = entries[0].strip()
else:
    print(f"Metadata validated for {tag}.")
    raise SystemExit(0)

name = json.loads(Path("config.json").read_text(encoding="utf-8"))["name"]
with Path(os.environ["GITHUB_OUTPUT"]).open("a", encoding="utf-8") as output:
    print("publish=true", f"title={name} {tag}", f"notes={notes}", sep="\n", file=output)
