define([
  'jquery',
  'underscore',
  'oro/translator',
  'pim/form',
  'routing',
  'pim/router',
  'pim/dialog',
  'oro/messenger',
  'acme/product-cleanup/template/products-without-family',
], function ($, _, __, BaseForm, Routing, router, Dialog, messenger, template) {
  'use strict';

  /**
   * Paginierte Liste der Produkte ohne Familie mit Lösch-Button (Hintergrund-Job)
   */
  return BaseForm.extend({
    template: _.template(template),

    events: {
      'click .acme-show-product': 'showProduct',
      'click .acme-show-job': 'showJob',
      'click .acme-show-log': 'showLog',
      'click .acme-page-previous:not(.AknButton--disabled)': 'previousPage',
      'click .acme-page-next:not(.AknButton--disabled)': 'nextPage',
      'click .acme-delete-all:not(.AknButton--disabled)': 'confirmDelete',
    },

    page: 1,
    data: null,
    error: null,
    launchedJobId: null,

    initialize: function (config) {
      this.config = config.config;

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
          pageCount: this.getPageCount(),
          launchedJobId: this.launchedJobId,
        })
      );

      return this;
    },

    loadPage: function (page) {
      this.page = page;

      return $.get(Routing.generate('acme_products_without_family_rest_list', {page: page, limit: this.config.limit}))
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

    previousPage: function () {
      this.loadPage(this.page - 1);
    },

    nextPage: function () {
      this.loadPage(this.page + 1);
    },

    showProduct: function (event) {
      router.redirectToRoute('pim_enrich_product_edit', {id: $(event.currentTarget).data('id')});
    },

    showJob: function () {
      router.redirectToRoute('pim_enrich_job_tracker_show', {id: this.launchedJobId});
    },

    showLog: function () {
      router.redirectToRoute('acme_deletion_log_index');
    },

    confirmDelete: function () {
      Dialog.confirmDelete(
        __('acme_product_cleanup.without_family.confirm.content', {count: this.data.total}),
        __('acme_product_cleanup.without_family.confirm.title'),
        this.launchDelete.bind(this),
        __('acme_product_cleanup.without_family.title')
      );
    },

    launchDelete: function () {
      $.post(Routing.generate('acme_products_without_family_rest_delete'))
        .then(response => {
          this.launchedJobId = response.job_execution_id;
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
