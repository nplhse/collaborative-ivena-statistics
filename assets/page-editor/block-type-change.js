// Rules for changing the type of a page content block in the editor (#967).
// `rules` comes from the server (PageContentBlockType enum): per type the content fields and,
// per target type, the fields that keep their meaning and are carried over.

/**
 * True when a field value holds content: text other than markup and whitespace, an image, or a
 * non-empty list. Mirrors PageContentBlockTypeChange::hasContent() on the server.
 */
export function hasContent(value) {
    if (Array.isArray(value)) {
        return value.some(hasContent);
    }

    if (null !== value && 'object' === typeof value) {
        return Object.values(value).some(hasContent);
    }

    if ('string' !== typeof value) {
        return null !== value && undefined !== value && false !== value;
    }

    if (/<img\b/i.test(value)) {
        return true;
    }

    return (
        '' !==
        value
            .replace(/<[^>]*>/g, '')
            .replace(/&nbsp;|\u00a0/g, ' ')
            .trim()
    );
}

/** Fields carried over unchanged from `fromType` to `toType`. */
export function transferableFields(rules, fromType, toType) {
    return rules[fromType]?.transferTo?.[toType] ?? [];
}

/**
 * Content fields of `fromType` that hold content and would be lost by changing to `toType`.
 * `values` maps field names to the current values of the block.
 */
export function lostContentFields(rules, fromType, toType, values) {
    if (fromType === toType) {
        return [];
    }

    const kept = transferableFields(rules, fromType, toType);

    return (rules[fromType]?.content ?? []).filter(
        (field) => !kept.includes(field) && hasContent(values[field]),
    );
}

/** Text of a block header: type label and enabled state, as the server renders it. */
export function blockHeaderLabel(typeLabel, enabled, labels) {
    return `${typeLabel} (${enabled ? labels.enabled : labels.disabled})`;
}

/** Text of an accordion item header: its title, or a numbered fallback while the title is empty. */
export function accordionItemHeaderLabel(title, position, fallbackPattern) {
    const trimmed = (title ?? '').trim();

    return '' !== trimmed ? trimmed : fallbackPattern.replace('{number}', String(position));
}
