define([
  'jquery',
  'underscore',
  'oro/translator',
  'pim/form',
  'routing',
  'acme/product-cleanup/template/deletion-log',
], function ($, _, __, BaseForm, Routing, template) {
  'use strict';

  /**
   * Lösch-Log: gelöschte Produkte und Product Models, neueste zuerst, mit Typfilter und Suche
   */
  return BaseForm.extend({
    template: _.template(template),

    events: {
      'change .acme-type-filter': 'changeType',
      'keyup .acme-search': 'changeSearch',
      'click .acme-page-previous:not(.AknButton--disabled)': 'previousPage',
      'click .acme-page-next:not(.AknButton--disabled)': 'nextPage',
    },

    page: 1,
    type: '',
    search: '',
    data: null,
    error: null,

    initialize: function (config) {
      this.config = config.config;
      this.debouncedLoad = _.debounce(() => this.loadPage(1), 400);

      BaseForm.prototype.initialize.apply(this, arguments);
    },

    configure: function () {
      this.loadPage(1);

      return BaseForm.prototype.configure.apply(this, arguments);
    },

    render: function () {
      const focused = this.$('.acme-search').is(':focus');

      this.$el.html(
        this.template({
          __: __,
          data: this.data,
          error: this.error,
          page: this.page,
          type: this.type,
          search: this.search,
          pageCount: this.getPageCount(),
        })
      );

      if (focused) {
        const input = this.$('.acme-search').focus()[0];
        input.setSelectionRange(input.value.length, input.value.length);
      }

      return this;
    },

    loadPage: function (page) {
      this.page = page;

      return $.get(
        Routing.generate('acme_deletion_log_rest_list', {
          page: page,
          limit: this.config.limit,
          type: this.type,
          search: this.search,
        })
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

    changeType: function (event) {
      this.type = $(event.currentTarget).val();
      this.loadPage(1);
    },

    changeSearch: function (event) {
      const value = $(event.currentTarget).val();
      if (value !== this.search) {
        this.search = value;
        this.debouncedLoad();
      }
    },

    previousPage: function () {
      this.loadPage(this.page - 1);
    },

    nextPage: function () {
      this.loadPage(this.page + 1);
    },
  });
});
