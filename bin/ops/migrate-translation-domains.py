#!/usr/bin/env python3
"""One-time migration: split messages domain and update code references."""

from __future__ import annotations

import re
import sys
import xml.etree.ElementTree as ET
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TRANSLATIONS = ROOT / "translations"
NS = {"xliff": "urn:oasis:names:tc:xliff:document:1.2"}
ET.register_namespace("", "urn:oasis:names:tc:xliff:document:1.2")

# Keys consolidated to action.cancel in messages (phase 7)
CANCEL_ALIASES = {
    "btn.cancel",
    "import.delete.modal.cancel",
    "stats.indication.compare.edit_modal.cancel",
    "stats.benchmark.selection.modal.cancel",
}

SAVE_CHANGES_ALIASES = {"btn.save.changes"}


def classify_key(key: str) -> str:
    if key in ("B+", "H+", "N+", "R+", "S+"):
        return "allocation"

    if key.startswith("action."):
        return "messages"
    if key in ("btn.cancel", "btn.search", "label.btn.save"):
        return "messages"
    if key.startswith(("text.", "cta.", "subtitle.", "info.")):
        return "messages"

    if key.startswith("stats.") or key.startswith("statistics."):
        return "statistics"

    if key.startswith("monthly_reminder."):
        return "engagement"
    if key.startswith("onboarding."):
        return "onboarding"
    if key.startswith("feedback."):
        return "feedback"

    if key.startswith(("import.", "imports.")) or key.startswith("flash.import"):
        return "import"

    if key.startswith(
        (
            "email.",
            "title.settings",
            "label.settings",
            "help.settings",
            "flash.settings",
            "flash.reset_password",
            "flash.registration",
            "flash.security",
            "title.register",
            "text.register",
            "pwd",
            "regterms",
            "regCheckEmail",
            "titleRegisterCheckEmail",
            "regNoEmail",
        )
    ):
        return "user"
    if key.startswith("reg") and not key.startswith("registration"):
        return "user"

    if key.startswith(
        (
            "blog.",
            "page.",
            "public.",
            "dashboard.",
            "media.",
            "flash.page",
            "flash.blog",
            "error.blog",
            "label.blog",
            "label.block_type",
            "label.page",
            "label.headline",
            "label.highlight",
            "label.spacing",
            "label.image_",
            "label.media",
            "help.page",
            "help.blog",
            "menuBlg",
            "dsh",
        )
    ):
        return "content"
    if key.startswith("page00") or key.startswith("blog00"):
        return "content"

    if key.startswith(
        (
            "allocation.",
            "allocations.",
            "indication.",
            "btn.indication",
            "flash.indication",
            "title.indication",
            "help.indication",
            "flash.hospital",
            "flash.hospital_access_grant",
            "confirm.hospital",
            "abbr.",
            "alloc",
            "SecInd",
            "Ind",
            "BtnInd",
            "FlsInd",
            "HlpInd",
            "hag",
            "hospital.size.",
            "title.mci",
            "title.allocation",
            "title.hospital",
            "title.secondary_transport",
            "title.explore",
            "title.hospital_access",
            "impFl",
            "impDel",
            "impSt",
            "impAct",
            "impProc",
            "impFlt",
            "lblWorkAccident",
            "allocFeat",
            "allocAll",
            "allocIs",
            "label.work_accident",
            "label.hospital_permission",
            "help.export",
            "help.no_hospitals",
            "HlpHag",
            "HlpMed",
            "lnkAlloc",
            "lnkHosp",
            "lnkMci",
            "lnkAssign",
            "lnkDept",
            "lnkDispatch",
            "lnkIndic",
            "lnkOccasion",
            "lnkInfect",
            "lnkSecTrans",
            "lnkSpec",
        )
    ):
        return "allocation"
    if key.startswith("mci") or key == "imports.title":
        return "allocation"

    if key.startswith(("admin.", "admin_notification.", "flash.admin", "admPg", "adminInd")):
        return "admin"

    if key.startswith(("cookie.", "locale.", "link.", "menu.", "flash.cookies", "Loc", "Cky", "lnkCookie", "lnkFeatures", "lnkTerms", "lnkHome", "lnkAbout", "lnkUsers", "lnkImport")):
        return "shared"

    if key.startswith("error.") and not key.startswith("error.blog"):
        return "errors"

    if key.startswith("flash."):
        if key.startswith("flash.cookies"):
            return "shared"
        return "messages"

    if key.startswith("label."):
        if key.startswith(("label.settings", "label.password", "label.confirm_password", "label.forgot", "label.email", "label.have_account", "label.authenticated")):
            return "user"
        if key.startswith(("label.blog", "label.block_type", "label.page", "label.headline", "label.highlight", "label.spacing", "label.image_", "label.media", "label.cta_link")):
            return "content"
        if key.startswith(("label.import", "label.export.period")):
            return "import"
        return "messages"

    if key.startswith("field."):
        return "messages"

    if key.startswith("help."):
        if key.startswith("help.indication"):
            return "allocation"
        if key.startswith(("help.page", "help.blog", "help.media")):
            return "content"
        if key.startswith("help.settings"):
            return "user"
        if key.startswith(("help.search", "help.export")):
            return key.startswith("help.export") and "allocation" or "shared"
        return "messages"

    if key.startswith("btn."):
        if key.startswith("btn.indication"):
            return "allocation"
        if key in ("btn.save.comment", "BtnSavCmt", "btnSaveCmt"):
            return "allocation"
        return "messages"

    if key.startswith("title."):
        if key.startswith("title.settings") or key.startswith("title.register"):
            return "user"
        if key.startswith("title.indication") or key.startswith("title.allocation") or key.startswith("title.hospital") or key.startswith("title.mci"):
            return "allocation"
        if key.startswith("title.import"):
            return "import"
        return "messages"

    if key.startswith("confirm."):
        return "messages" if not key.startswith("confirm.hospital") else "allocation"

    if key.startswith(("notif", "warning.", "subtitle")):
        return "shared" if key.startswith("notif") else "messages"

    return "messages"


def effective_key(key: str) -> str:
    if key in CANCEL_ALIASES:
        return "action.cancel"
    if key in SAVE_CHANGES_ALIASES:
        return "action.save_changes"
    return key


def parse_xlf(path: Path) -> list[ET.Element]:
    tree = ET.parse(path)
    root = tree.getroot()
    body = root.find("xliff:file/xliff:body", NS)
    if body is None:
        return []
    return list(body.findall("xliff:trans-unit", NS))


def write_domain_file(domain: str, units: list[ET.Element], locale: str) -> None:
    suffix = "+intl-icu" if domain not in ("validators", "security") else ""
    path = TRANSLATIONS / f"{domain}{suffix}.{locale}.xlf"

    file_el = ET.Element("file", {
        "source-language": "en",
        "target-language": locale,
        "datatype": "plaintext",
        "original": "file.ext",
    })
    header = ET.SubElement(file_el, "header")
    ET.SubElement(header, "tool", {"tool-id": "symfony", "tool-name": "Symfony"})
    body = ET.SubElement(file_el, "body")
    for unit in units:
        body.append(unit)

    root = ET.Element("{urn:oasis:names:tc:xliff:document:1.2}xliff", {"version": "1.2"})
    root.append(file_el)

    tree = ET.ElementTree(root)
    ET.indent(tree, space="  ")
    path.write_text('<?xml version="1.0" encoding="utf-8"?>\n', encoding="utf-8")
    with path.open("ab") as f:
        tree.write(f, encoding="utf-8", xml_declaration=False)


def split_messages() -> dict[str, str]:
    """Split messages XLF into domains. Returns key -> domain mapping."""
    key_domain: dict[str, str] = {}

    for locale in ("en", "de"):
        messages_path = TRANSLATIONS / f"messages+intl-icu.{locale}.xlf"
        units = parse_xlf(messages_path)
        by_domain: dict[str, list[ET.Element]] = defaultdict(list)
        messages_units: list[ET.Element] = []

        for unit in units:
            key = unit.get("resname") or unit.get("id") or ""
            domain = classify_key(key)
            eff = effective_key(key)

            if locale == "en":
                key_domain[key] = domain
                if eff != key:
                    key_domain[eff] = domain

            if eff != key and domain == "messages":
                continue  # alias removed from catalogue

            by_domain[domain].append(unit)

        write_domain_file("messages", by_domain["messages"], locale)
        for domain in sorted(by_domain.keys()):
            if domain == "messages":
                continue
            if domain == "errors":
                existing = parse_xlf(TRANSLATIONS / f"errors+intl-icu.{locale}.xlf") if (TRANSLATIONS / f"errors+intl-icu.{locale}.xlf").exists() else []
                existing_keys = {u.get("resname") or u.get("id") for u in existing}
                new_units = [u for u in by_domain["errors"] if (u.get("resname") or u.get("id")) not in existing_keys]
                write_domain_file("errors", existing + new_units, locale)
            elif domain == "admin":
                existing_path = TRANSLATIONS / f"admin+intl-icu.{locale}.xlf"
                existing = parse_xlf(existing_path) if existing_path.exists() else []
                existing_keys = {u.get("resname") or u.get("id") for u in existing}
                new_units = [u for u in by_domain["admin"] if (u.get("resname") or u.get("id")) not in existing_keys]
                write_domain_file("admin", existing + new_units, locale)
            else:
                write_domain_file(domain, by_domain[domain], locale)

    return key_domain


def build_key_domain_map() -> dict[str, str]:
    mapping: dict[str, str] = {}
    for xlf in TRANSLATIONS.glob("*.xlf"):
        name = xlf.name
        if name.startswith("EasyAdmin") or name.startswith("ResetPassword") or name.startswith("VerifyEmail"):
            continue
        domain = name.split("+intl-icu")[0].split(".")[0]
        for unit in parse_xlf(xlf):
            key = unit.get("resname") or unit.get("id") or ""
            if key:
                mapping[key] = domain
    for alias in CANCEL_ALIASES:
        mapping[alias] = "messages"
    mapping["action.cancel"] = "messages"
    for alias in SAVE_CHANGES_ALIASES:
        mapping[alias] = "messages"
    mapping["action.save_changes"] = "messages"
    return mapping


def update_php_file(path: Path, key_domain: dict[str, str]) -> bool:
    content = path.read_text(encoding="utf-8")
    original = content

    def repl_trans(match: re.Match[str]) -> str:
        key = match.group(1)
        if key_domain.get(key) in (None, "validators", "security"):
            return match.group(0)
        domain = key_domain[key]
        rest = match.group(2) or ""

        # ->trans('key', [], null, $locale)
        if re.match(r",\s*\[\],\s*null\s*,", rest):
            new_rest = rest.replace("[], null,", f"[], '{domain}',", 1)
            return f"->trans('{key}'{new_rest}"
        # ->trans('key', $params) or ->trans('key')
        if rest.startswith(", ["):
            if re.search(r",\s*'[a-z_]+'\s*\)", rest):
                return match.group(0)
            return f"->trans('{key}'{rest[:-1]}, '{domain}')"
        if rest == ")":
            return f"->trans('{key}', [], '{domain}')"
        return match.group(0)

    content = re.sub(
        r"->trans\(\s*'([^']+)'\s*(\)|,\s*\[[^\]]*\]\s*\)|,\s*\[[^\]]*\]\s*,\s*null\s*,\s*[^)]+)",
        repl_trans,
        content,
    )

    # Replace alias keys
    for alias, target in [(a, "action.cancel") for a in CANCEL_ALIASES]:
        content = content.replace(f"'{alias}'", f"'{target}'")
    for alias in SAVE_CHANGES_ALIASES:
        content = content.replace(f"'{alias}'", "'action.save_changes'")

    if content != original:
        path.write_text(content, encoding="utf-8")
        return True
    return False


def update_twig_file(path: Path, key_domain: dict[str, str]) -> bool:
    content = path.read_text(encoding="utf-8")
    original = content

    for alias in CANCEL_ALIASES:
        content = content.replace(f"'{alias}'", "'action.cancel'")
    for alias in SAVE_CHANGES_ALIASES:
        content = content.replace(f"'{alias}'", "'action.save_changes'")

    def replace_trans(match: re.Match[str]) -> str:
        full = match.group(0)
        key = match.group(1)
        domain = key_domain.get(key)
        if domain is None or domain in ("validators", "security"):
            return full
        if re.search(r",\s*'[a-z_]+'\s*\)", full):
            return full
        params = match.group(2)
        suffix = match.group(3) or ""
        if params is None:
            return f"'{key}'|trans({{}}, '{domain}'){suffix}"
        return f"'{key}'|trans({params}, '{domain}'){suffix}"

    # 'key'|trans({...})|filter
    content = re.sub(
        r"'([^']+)'\|trans(\(\{[^}]*\}\))?(\|[^|]+)?",
        replace_trans,
        content,
    )
    # 'key'|trans|filter (no parens)
    content = re.sub(
        r"'([^']+)'\|trans(\|[^|]+)",
        lambda m: replace_trans(type("M", (), {
            "group": lambda self, i: {1: m.group(1), 2: None, 3: m.group(2), 0: m.group(0)}[i]
        })()),
        content,
    )

    if content != original:
        path.write_text(content, encoding="utf-8")
        return True
    return False


def update_form_domains(key_domain: dict[str, str]) -> None:
    form_map = {
        "HospitalAddressType.php": "messages",
        "HospitalParticipantEditType.php": "allocation",
        "HospitalParticipantAddressEditType.php": "messages",
        "OwnHospitalAllocationsExportType.php": "allocation",
        "FeedbackSubmitFormType.php": "feedback",
        "ExplorerEditFormType.php": "statistics",
        "RegistrationFormType.php": "user",
        "SettingsLocaleType.php": "user",
        "SettingsPasswordType.php": "user",
        "SettingsEmailType.php": "user",
        "SettingsNotificationsType.php": "user",
    }
    for rel, domain in form_map.items():
        for path in ROOT.glob(f"src/**/{rel}"):
            content = path.read_text(encoding="utf-8")
            content = re.sub(
                r"'translation_domain'\s*=>\s*'messages'",
                f"'translation_domain' => '{domain}'",
                content,
            )
            content = re.sub(
                r"'choice_translation_domain'\s*=>\s*'messages'",
                f"'choice_translation_domain' => '{domain}'",
                content,
            )
            if "'translation_domain'" not in content and "FormType" in content:
                content = re.sub(
                    r"(public function configureOptions\(OptionsResolver \$resolver\): void\s*\{\s*\n\s*\$resolver->setDefaults\(\[)",
                    f"\\1\n            'translation_domain' => '{domain}',",
                    content,
                    count=1,
                )
            path.write_text(content, encoding="utf-8")


def main() -> int:
    print("Splitting messages XLF...")
    split_messages()
    print("Building key-domain map...")
    key_domain = build_key_domain_map()
    print(f"Mapped {len(key_domain)} keys")

    php_changed = 0
    for path in list(ROOT.glob("src/**/*.php")) + list(ROOT.glob("tests/**/*.php")):
        if update_php_file(path, key_domain):
            php_changed += 1
    print(f"Updated {php_changed} PHP files")

    twig_changed = 0
    for path in list(ROOT.glob("src/**/*.twig")) + list(ROOT.glob("templates/**/*.twig")):
        if update_twig_file(path, key_domain):
            twig_changed += 1
    print(f"Updated {twig_changed} Twig files")

    update_form_domains(key_domain)
    print("Updated form translation domains")
    return 0


if __name__ == "__main__":
    sys.exit(main())
