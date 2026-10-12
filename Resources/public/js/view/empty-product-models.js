define([
  'jquery',
  'underscore',
  'oro/translator',
  'pim/form',
  'routing',
  'pim/router',
  'pim/dialog',
  'oro/messenger',
  'acme/product-cleanup/template/empty-product-models',
], function ($, _, __, BaseForm, Routing, router, Dialog, messenger, template) {
  'use strict';

  /**
   * Paginierte Liste leerer Product Models (optional je Familie). Löschen per Hintergrund-Job:
   * alle, die ersten N (feste Mengen) oder angehakte Models (Auswahl bleibt beim Blättern erhalten).
   */
  return BaseForm.extend({
    template: _.template(template),

    events: {
      'click .acme-show-model': 'showProductModel',
      'click .acme-show-job': 'showJob',
      'change .acme-family-filter': 'changeFamily',
      'click .acme-page-previous:not(.AknButton--disabled)': 'previousPage',
      'click .acme-page-next:not(.AknButton--disabled)': 'nextPage',
      'click .acme-delete-all:not(.AknButton--disabled)': 'confirmDeleteAll',
      'click .acme-delete-limit:not(.AknButton--disabled)': 'confirmDeleteLimit',
      'click .acme-delete-selected:not(.AknButton--disabled)': 'confirmDeleteSelected',
      'click .acme-clear-selection': 'clearSelection',
      'change .acme-select-model': 'toggleModel',
      'change .acme-select-page': 'togglePage',
    },

    page: 1,
    family: '',
    data: null,
    error: null,
    launchedJobId: null,
    selected: null,

    initialize: function (config) {
      this.config = config.config;
      this.selected = {};

      BaseForm.prototype.initialize.apply(this, arguments);
    },

    configure: function () {
      this.loadPage(1);

      return BaseForm.prototype.configure.apply(this, arguments);
    },

    render: function () {
      this.$el.html(
        this.template({
          __: __,
          data: this.data,
          error: this.error,
          page: this.page,
          family: this.family,
          pageCount: this.getPageCount(),
          launchedJobId: this.launchedJobId,
          selected: this.selected,
          selectedCount: _.size(this.selected),
          pageSelected: this.isPageSelected(),
        })
      );

      return this;
    },

    loadPage: function (page) {
      this.page = page;

      return $.get(
        Routing.generate('acme_empty_product_models_rest_list', {page: page, limit: this.config.limit, family: this.family})
      )
        .then(data => {
          this.data = data;
          this.error = null;
        })
        .fail(response => {
          this.error =
            (response.responseJSON && response.responseJSON.message) || __('pim_enrich.entity.fallback.generic_error');
        })
        .always(() => this.render());
    },

    getPageCount: function () {
      return null === this.data ? 0 : Math.ceil(this.data.total / this.data.limit);
    },

    changeFamily: function (event) {
      this.family = $(event.currentTarget).val();
      this.selected = {};
      this.loadPage(1);
    },

    isPageSelected: function () {
      return (
        null !== this.data && this.data.items.length > 0 && _.every(this.data.items, item => this.selected[item.code])
      );
    },

    toggleModel: function (event) {
      const code = String($(event.currentTarget).data('code'));

      if (event.currentTarget.checked) {
        if (!this.canSelect(1)) {
          event.currentTarget.checked = false;

          return;
        }
        this.selected[code] = true;
      } else {
        delete this.selected[code];
      }
      this.render();
    },

    togglePage: function (event) {
      const codes = _.pluck(this.data.items, 'code');

      if (event.currentTarget.checked) {
        const newCodes = _.reject(codes, code => this.selected[code]);
        if (!this.canSelect(newCodes.length)) {
          event.currentTarget.checked = false;

          return;
        }
        newCodes.forEach(code => (this.selected[code] = true));
      } else {
        codes.forEach(code => delete this.selected[code]);
      }
      this.render();
    },

    canSelect: function (additional) {
      if (_.size(this.selected) + additional <= this.data.max_selected) {
        return true;
      }
      messenger.notify('error', __('acme_product_cleanup.page.max_selected', {max: this.data.max_selected}));

      return false;
    },

    clearSelection: function () {
      this.selected = {};
      this.render();
    },

    previousPage: function () {
      this.loadPage(this.page - 1);
    },

    nextPage: function () {
      this.loadPage(this.page + 1);
    },

    showProductModel: function (event) {
      if ($(event.target).closest('.acme-select-cell').length > 0) {
        return;
      }
      router.redirectToRoute('pim_enrich_product_model_edit', {id: $(event.currentTarget).data('id')});
    },

    showJob: function () {
      router.redirectToRoute('pim_enrich_job_tracker_show', {id: this.launchedJobId});
    },

    confirmDeleteAll: function () {
      this.confirmDelete(__('acme_product_cleanup.page.confirm.content', {count: this.data.total}), {});
    },

    confirmDeleteLimit: function (event) {
      const limit = $(event.currentTarget).data('limit');

      this.confirmDelete(__('acme_product_cleanup.page.confirm.content_limit', {count: limit}), {limit: limit});
    },

    confirmDeleteSelected: function () {
      const codes = _.keys(this.selected);

      this.confirmDelete(__('acme_product_cleanup.page.confirm.content_selected', {count: codes.length}), {codes: codes});
    },

    confirmDelete: function (content, payload) {
      const familyHint = this.family ? ' ' + __('acme_product_cleanup.page.confirm.family_hint', {family: this.family}) : '';

      Dialog.confirmDelete(
        content + familyHint + ' ' + __('acme_product_cleanup.page.confirm.kept'),
        __('acme_product_cleanup.page.confirm.title'),
        this.launchDelete.bind(this, payload),
        __('acme_product_cleanup.page.title')
      );
    },

    launchDelete: function (payload) {
      $.post(Routing.generate('acme_empty_product_models_rest_delete'), _.extend({family: this.family}, payload))
        .then(response => {
          this.launchedJobId = response.job_execution_id;
          this.selected = {};
          messenger.notify('success', __('acme_product_cleanup.page.job_launched'));
          this.render();
        })
        .fail(response => {
          messenger.notify(
            'error',
            (response.responseJSON && response.responseJSON.message) || __('pim_enrich.entity.fallback.generic_error')
          );
        });
    },
  });
});
