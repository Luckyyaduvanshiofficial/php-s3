// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

export default defineConfig({
	site: 'https://php-s3.codaipro.com',
	integrations: [
		starlight({
			title: 'php-s3',
			description: 'The self-hosted S3-compatible object storage server that runs on shared hosting',
			favicon: '/favicon.svg',
			customCss: ['./src/styles/custom.css'],
			expressiveCode: {
				themes: ['gruvbox-dark-hard', 'gruvbox-light-medium'],
				styleOverrides: {
					borderRadius: '0.25rem',
					borderWidth: '1px',
					codeFontSize: '0.8125rem',
				},
			},
			head: [
				{
					tag: 'meta',
					attrs: { name: 'theme-color', content: '#f6f3ed', media: '(prefers-color-scheme: light)' },
				},
				{
					tag: 'meta',
					attrs: { name: 'theme-color', content: '#272219', media: '(prefers-color-scheme: dark)' },
				},
			],
			social: [
				{ icon: 'github', label: 'GitHub', href: 'https://github.com/Luckyyaduvanshiofficial/php-s3' },
			],
			sidebar: [
				{
					label: 'Getting Started',
					items: [
						{ label: 'Installation', slug: 'docs/install' },
						{ label: 'Hostinger Guide', slug: 'docs/hostinger' },
						{ label: 'Usage & SDKs', slug: 'docs/usage' },
					],
				},
				{
					label: 'Reference & Architecture',
					items: [
						{ label: 'Architecture', slug: 'docs/architecture' },
						{ label: 'S3 Compatibility', slug: 'docs/s3-compatibility' },
						{ label: 'Research & Audit', slug: 'docs/research' },
					],
				},
			],
		}),
	],
});
