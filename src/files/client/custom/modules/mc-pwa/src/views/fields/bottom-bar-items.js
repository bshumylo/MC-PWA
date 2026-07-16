/**
 * MC PWA – bottom navigation bar buttons.
 *
 * Reuses the core Tab List editor (Administration → User Interface):
 * drag-and-drop ordering, adding entity tabs, and custom URL buttons
 * with a label, icon, icon color and access options.
 */
define(['views/settings/fields/tab-list'], (Dep) => {

    return class extends Dep {

        noGroups = true
        noDelimiters = true

        addItemModalView = 'mc-pwa:views/modals/bottom-bar-add'

        setup() {
            // Convert items of the legacy (v1.0.9) format beforehand.
            const list = this.model.get(this.name);

            if (Array.isArray(list)) {
                this.model.set(
                    this.name,
                    list.map(item => this.convertLegacyItem(item)),
                    {silent: true}
                );
            }

            super.setup();
        }

        convertLegacyItem(item) {
            if (
                !item ||
                typeof item !== 'object' ||
                item.type ||
                item.url === undefined
            ) {
                return item;
            }

            const url = String(item.url || '');

            const m = url.match(/^#([A-Za-z][A-Za-z0-9]*)$/);

            if (m && this.getMetadata().get(['scopes', m[1]])) {
                return m[1];
            }

            return {
                type: 'url',
                text: item.label || url,
                url: url,
                iconClass: item.iconClass || null,
                color: item.iconColor || null,
                aclScope: null,
                onlyAdmin: false,
                openInNewTab: /^https?:\/\//i.test(url),
            };
        }
    };
});
