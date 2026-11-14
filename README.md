# AIOHM Knowledge Assistant

An AI assistant for WordPress that answers from your own content, in your brand voice.

The plugin reads your posts, pages and documents and builds a knowledge base inside your WordPress database. A chatbot and search box then answer visitor questions from that knowledge base, not from the open internet.

Since version 3.0.0 every feature from the former Pro version is included and free. There are no licence keys and no calls to a licensing server.

## Features

- Public chatbot and search that answer from your own content
- Private assistant for your team
- PDF, CSV, TXT and JSON document import
- Brand voice settings
- AI providers: OpenAI, Claude, Gemini, Groq and ShareAI
- Local AI with Ollama, so content can stay on your own server
- MCP endpoint, so MCP clients such as Claude Desktop can query your knowledge base
- White-label mode for agencies

## Requirements

- WordPress 6.2 or later
- PHP 7.4 or later
- An API key for one AI provider, or a reachable Ollama server

## Install

1. Download the latest release zip from the Releases page.
2. In WordPress, go to Plugins, then Add New, then Upload Plugin.
3. Upload the zip and activate it.
4. Open AIOHM in the admin menu and add your AI provider in Settings.

The plugin is also listed on WordPress.org as AIOHM Knowledge Assistant.

## Build from source

```bash
composer install --no-dev --optimize-autoloader
```

## Contributing

Issues and pull requests are welcome. Please follow the WordPress coding standards.

## Part of the AIOHM and OHM Events family

This plugin gives one WordPress site an assistant that knows its content. [AIOHM](https://aiohm.org) does the same for a whole marketing operation: brand memory and knowledge for every project in one private workspace, connected to WordPress, email and social publishing, with an approval step before anything goes out.

- **[AIOHM](https://aiohm.org)**: private marketing infrastructure with memory. One brand memory across strategy, content, distribution, web and commerce. Human leads. Muse orchestrates. Agents execute. You approve.
- **[OHM Events](https://www.ohm.events)**: the event growth agency behind AIOHM. SEO, ads, email and web for event founders, plus more free tools in the [Open source shop](https://www.ohm.events/product-category/open-source/).
- **[AdiSaan](https://adisaan.com)**: an open observatory of human imagination and AI. People and AI models answer the same real question side by side, so you can see where human answers differ and where AI answers converge.

## License

GPLv2 or later. See [LICENSE](LICENSE).

Made by [OHM Events Agency](https://www.ohm.events) and [AIOHM](https://aiohm.org).
