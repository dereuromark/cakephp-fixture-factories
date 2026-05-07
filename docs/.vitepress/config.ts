import { defineConfig } from 'vitepress'

const docsBase = process.env.DOCS_BASE ?? '/cakephp-fixture-factories/'
const currentVersion = process.env.DOCS_VERSION_TEXT ?? 'v2 (latest)'
const latestLinkText = process.env.DOCS_LATEST_LINK_TEXT ?? 'v2 (latest)'
const legacyLinkText = process.env.DOCS_LEGACY_LINK_TEXT ?? 'v1.x (legacy)'
const docsSeries = process.env.DOCS_SERIES ?? 'v2'
const editBranch = process.env.DOCS_EDIT_BRANCH ?? 'main'
const isLegacyDocs = docsSeries === 'v1'

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
          items: isLegacyDocs
            ? [
                { text: '1.3 → 1.4', link: '/guide/upgrading' },
                { text: 'Migration from vierge-noire', link: '/guide/migration' },
              ]
            : [
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
      pattern: `https://github.com/dereuromark/cakephp-fixture-factories/edit/${editBranch}/docs/:path`,
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

    versioning: {
      currentVersion,
      latestLinkText,
      legacyLinkText,
      latestPath: '/cakephp-fixture-factories/',
      legacyPath: '/cakephp-fixture-factories/1.x/',
    },
  },

  markdown: {
    theme: {
      light: 'github-light',
      dark: 'github-dark',
    },
  },
})
