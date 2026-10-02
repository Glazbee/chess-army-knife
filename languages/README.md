# Translations

Translation files for the plugin go here (`chess-army-knife-<locale>.mo` and `.po`). The editor's
text is loaded from `chess-army-knife-<locale>-<script hash>.json` files in this folder, which
`wp i18n make-json languages --no-purge` makes from the `.po` files.

To make a template for translators: `wp i18n make-pot . languages/chess-army-knife.pot --exclude=node_modules,vendor,build,tests`.
