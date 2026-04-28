import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'CakePHP Fixture Factories',
  description: 'Write and run your tests faster — fixture factories for CakePHP and beyond.',

  base: '/cakephp-fixture-factories/',

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/cakephp-fixture-factories/logo.svg' }],
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
          text: 'Upgrading',
          items: [
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
      level: [2, 3],
    },
  },

  markdown: {
    theme: {
      light: 'github-light',
      dark: 'github-dark',
    },
  },
})
