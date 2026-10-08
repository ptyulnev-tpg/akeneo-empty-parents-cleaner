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
   * Paginierte Liste leerer Product Models (optional je Familie) mit Lösch-Button (Hintergrund-Job)
   */
  return BaseForm.extend({
    template: _.template(template),

    events: {
      'click .acme-show-model': 'showProductModel',
      'click .acme-show-job': 'showJob',
      'change .acme-family-filter': 'changeFamily',
      'click .acme-page-previous:not(.AknButton--disabled)': 'previousPage',
      'click .acme-page-next:not(.AknButton--disabled)': 'nextPage',
      'click .acme-delete-all:not(.AknButton--disabled)': 'confirmDelete',
    },

    page: 1,
    family: '',
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
          family: this.family,
          pageCount: this.getPageCount(),
          launchedJobId: this.launchedJobId,
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
      this.loadPage(1);
    },

    previousPage: function () {
      this.loadPage(this.page - 1);
    },

    nextPage: function () {
      this.loadPage(this.page + 1);
    },

    showProductModel: function (event) {
      router.redirectToRoute('pim_enrich_product_model_edit', {id: $(event.currentTarget).data('id')});
    },

    showJob: function () {
      router.redirectToRoute('pim_enrich_job_tracker_show', {id: this.launchedJobId});
    },

    confirmDelete: function () {
      const content = this.family
        ? __('acme_product_cleanup.page.confirm.content_family', {count: this.data.total, family: this.family})
        : __('acme_product_cleanup.page.confirm.content', {count: this.data.total});

      Dialog.confirmDelete(
        content,
        __('acme_product_cleanup.page.confirm.title'),
        this.launchDelete.bind(this),
        __('acme_product_cleanup.page.title')
      );
    },

    launchDelete: function () {
      $.post(Routing.generate('acme_empty_product_models_rest_delete'), {family: this.family})
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
