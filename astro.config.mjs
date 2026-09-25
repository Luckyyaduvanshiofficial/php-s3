// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

export default defineConfig({
	site: 'https://php-s3.codaipro.com',
	integrations: [
		starlight({
			title: 'php-s3',
			description: 'The self-hosted S3-compatible object storage server that runs on shared hosting',
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
