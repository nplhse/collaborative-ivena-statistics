// Tests of the page block type change rules (#967). Run with `npm run test:js`.
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    accordionItemHeaderLabel,
    blockHeaderLabel,
    hasContent,
    lostContentFields,
    transferableFields,
} from '../../../assets/page-editor/block-type-change.js';

// Same shape as PageTranslationCrudController::buildBlockTypeRules(), reduced to the types used here.
const rules = {
    richtext: { content: ['html'], transferTo: { highlight: ['html'] } },
    highlight: { content: ['title', 'html', 'icon'], transferTo: { richtext: ['html'] } },
    quote: { content: ['text'], transferTo: [] },
    headline: { content: ['text'], transferTo: [] },
    accordion: { content: ['items'], transferTo: [] },
};

describe('hasContent', () => {
    it('ignores empty markup and whitespace', () => {
        assert.equal(hasContent('<div><br></div>'), false);
        assert.equal(hasContent(' &nbsp; '), false);
        assert.equal(hasContent(''), false);
    });

    it('counts text, images and filled lists', () => {
        assert.equal(hasContent('<div>Text</div>'), true);
        assert.equal(hasContent('<p><img src="/a.png"></p>'), true);
        assert.equal(hasContent({ 0: { title: '', html: 'Answer' } }), true);
        assert.equal(hasContent({ 0: { title: '', html: '' } }), false);
    });
});

describe('lostContentFields', () => {
    it('reports content the new type cannot keep', () => {
        assert.deepEqual(lostContentFields(rules, 'richtext', 'quote', { html: '<p>Body</p>' }), [
            'html',
        ]);
    });

    it('keeps the rich text body between rich text and highlight', () => {
        assert.deepEqual(
            lostContentFields(rules, 'richtext', 'highlight', { html: '<p>Body</p>' }),
            [],
        );
        assert.deepEqual(
            lostContentFields(rules, 'highlight', 'richtext', {
                title: 'Note',
                html: '<p>Body</p>',
            }),
            ['title'],
        );
    });

    it('does not carry fields that only share a name', () => {
        assert.deepEqual(lostContentFields(rules, 'quote', 'headline', { text: 'A quote' }), [
            'text',
        ]);
        assert.deepEqual(transferableFields(rules, 'quote', 'headline'), []);
    });

    it('reports nothing for empty fields or an unchanged type', () => {
        assert.deepEqual(
            lostContentFields(rules, 'richtext', 'quote', { html: '<div><br></div>' }),
            [],
        );
        assert.deepEqual(lostContentFields(rules, 'quote', 'quote', { text: 'Same' }), []);
    });

    it('reports accordion items that hold content', () => {
        assert.deepEqual(
            lostContentFields(rules, 'accordion', 'richtext', {
                items: { 0: { title: 'Question', html: '' } },
            }),
            ['items'],
        );
    });
});

describe('header labels', () => {
    it('shows the block type and state', () => {
        const labels = { enabled: 'Enabled', disabled: 'Disabled' };
        assert.equal(blockHeaderLabel('Quote', true, labels), 'Quote (Enabled)');
        assert.equal(blockHeaderLabel('Quote', false, labels), 'Quote (Disabled)');
    });

    it('shows the accordion item title or a numbered fallback', () => {
        assert.equal(accordionItemHeaderLabel(' Question ', 1, 'Item {number}'), 'Question');
        assert.equal(accordionItemHeaderLabel('', 3, 'Item {number}'), 'Item 3');
    });
});
