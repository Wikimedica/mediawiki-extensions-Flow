# Flow Extension — Development Notes

## Handlebars Templates

Flow uses [LightnCandy](https://github.com/zordius/lightncandy) to compile Handlebars templates to PHP.
The compiled files live in `handlebars/compiled/`.

**After editing any `.handlebars` or `.partial.handlebars` file, you must recompile:**

```
php /home/antoine/Projects/Wikimedica/development/MediaWiki/maintenance/run.php \
  /home/antoine/Projects/Wikimedica/development/MediaWiki/extensions/Flow/maintenance/compileLightncandy.php
```

Changes to partial templates (e.g. `flow_moderation_actions_list.partial.handlebars`) are inlined
into the compiled output of the parent templates that include them. Until recompiled, your edits
will have no effect at runtime.

**New template files** also need to be registered in `extension.json` under the `templates` array
of the `ext.flow.templating` ResourceLoader module, otherwise the JS side will throw
"Template not found in module ext.flow.templating".

## i18n Messages in JS/Templates

Messages used by Handlebars templates (via `{{l10n "key"}}`) or JS code must also be listed in
the `messages` array of the appropriate ResourceLoader module in `extension.json`. Without this,
the message key appears as `⧼key⧽` in the UI. The main module is `ext.flow` (around line 200+
in `extension.json`).
