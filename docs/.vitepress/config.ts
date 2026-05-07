import { defineConfig } from 'vitepress'

const docsBase = process.env.DOCS_BASE ?? '/cakephp-fixture-factories/'
const docsSiteOrigin = process.env.DOCS_SITE_ORIGIN ?? 'https://dereuromark.github.io'
const currentVersion = process.env.DOCS_VERSION_TEXT ?? 'v2 (latest)'
const latestLinkText = process.env.DOCS_LATEST_LINK_TEXT ?? 'v2 (latest)'
const legacyLinkText = process.env.DOCS_LEGACY_LINK_TEXT ?? 'v1.x (legacy)'
const latestDocsUrl = `${docsSiteOrigin}/cakephp-fixture-factories/`
const legacyDocsUrl = `${docsSiteOrigin}/cakephp-fixture-factories/1.x/`

export default defineConfig({
  title: 'CakePHP Fixture Factories',
  description: 'Write and run your tests faster — fixture factories for CakePHP and beyond.',

  base: docsBase,

  head: [
    ['link', { rel: 'icon', type: 'image/png', sizes: '16x16', href: '/cakephp-fixture-factories/favicon-16.png' }],
    ['link', { rel: 'icon', type: 'image/png', sizes: '32x32', href: '/cakephp-fixture-factories/favicon.png' }],
    ['link', { rel: 'apple-touch-icon', sizes: '192x192', href: '/cakephp-fixture-factories/apple-touch-icon.png' }],
    ['link', { rel: 'shortcut icon', href: '/cakephp-fixture-factories/favicon.ico' }],
    ['meta', { name: 'theme-color', content: '#d33c43' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'CakePHP Fixture Factories' }],
    ['meta', { property: 'og:description', content: 'Write and run your tests faster — fixture factories for CakePHP and beyond.' }],
  ],

  themeConfig: {
    logo: '/logo.svg',

    nav: [
      { text: 'Guide', link: '/guide/', activeMatch: '/guide/' },
      { text: 'Reference', link: '/reference/bake', activeMatch: '/reference/' },
      {
        text: 'Links',
        items: [
          { text: 'Changelog', link: 'https://github.com/dereuromark/cakephp-fixture-factories/releases' },
          { text: 'Packagist', link: 'https://packagist.org/packages/dereuromark/cakephp-fixture-factories' },
          { text: 'Issues', link: 'https://github.com/dereuromark/cakephp-fixture-factories/issues' },
        ],
      },
      {
        text: currentVersion,
        items: [
          { text: latestLinkText, link: latestDocsUrl },
          { text: legacyLinkText, link: legacyDocsUrl },
        ],
      },
    ],

    sidebar: {
      '/guide/': [
        {
          text: 'Introduction',
          items: [
            { text: 'Getting Started', link: '/guide/' },
            { text: 'Why factories?', link: '/guide/why-factories' },
            { text: 'Setup', link: '/guide/setup' },
          ],
        },
        {
          text: 'Writing Factories',
          items: [
            { text: 'Fixture Factories', link: '/guide/factories' },
            { text: 'Usage Examples', link: '/guide/examples' },
            { text: 'Associations', link: '/guide/associations' },
            { text: 'Associations (non-CakePHP)', link: '/guide/non-cakephp-associations' },
            { text: 'Scenarios', link: '/guide/scenarios' },
            { text: 'Best Practices', link: '/guide/best-practices' },
          ],
        },
        {
          text: 'Querying & Generators',
          items: [
            { text: 'Queries', link: '/guide/queries' },
            { text: 'Generators', link: '/guide/generators' },
          ],
        },
        {
          text: 'Help & Reference',
          items: [
            { text: 'FAQ', link: '/guide/faq' },
            { text: 'Troubleshooting', link: '/guide/troubleshooting' },
          ],
        },
        {
          text: 'Upgrading',
          items: [
            { text: 'v1 → v2', link: '/guide/upgrading' },
            { text: 'Migration from vierge-noire', link: '/guide/migration' },
          ],
        },
      ],
      '/reference/': [
        {
          text: 'CLI Commands',
          items: [
            { text: 'Bake', link: '/reference/bake' },
            { text: 'Persist', link: '/reference/persist' },
          ],
        },
        {
          text: 'Configuration',
          items: [
            { text: 'Reference', link: '/reference/configuration' },
          ],
        },
      ],
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/dereuromark/cakephp-fixture-factories' },
    ],

    editLink: {
      pattern: 'https://github.com/dereuromark/cakephp-fixture-factories/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © Mark Scherer & contributors. Originally by Juan Pablo Ramirez and Nicolas Masson.',
    },

    search: {
      provider: 'local',
    },

    outline: {
      level: [2, 4],
    },
  },

  markdown: {
    theme: {
      light: 'github-light',
      dark: 'github-dark',
    },
  },
})
