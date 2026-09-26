{{-- Shared look of the bill PDFs (appointment bill + shop order bill). dompdf: tables and floats only — no flex/grid. --}}
<style>
    /* The top margin only shows from page 2 on (the header pulls itself flush to the top of page 1);
       the bottom margin keeps table rows clear of the footer. */
    @page { margin: 34px 0 62px 0; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { margin: 0; color: #201e1b; font-size: 10.5px; line-height: 1.45; }

    /* Header: accent strip, then the logo on the site's cream */
    .top { margin-top: -34px; }
    .strip { height: 7px; background: #9a7b53; }
    .band { background: #f7f3ea; border-bottom: 1px solid #e4ddce; padding: 24px 44px 22px; }
    .band table { width: 100%; border-collapse: collapse; }
    .band td { padding: 0; vertical-align: middle; }
    .logo { width: 112px; }
    .contact { margin-top: 9px; font-size: 8.5px; line-height: 1.55; color: #6d6858; }
    .doc-title { font-family: 'DejaVu Serif', serif; font-size: 25px; letter-spacing: 5px; text-transform: uppercase; text-align: right; color: #201e1b; }
    .doc-ref { margin-top: 7px; text-align: right; font-size: 9.5px; letter-spacing: 0.5px; color: #6d6858; }
    .badge { display: inline-block; margin-top: 11px; padding: 4px 14px; border-radius: 12px; background: #23503a; color: #ffffff; font-size: 8.5px; font-weight: bold; letter-spacing: 1.6px; }
    .badge.pending { background: #9a6a00; }
    .right { text-align: right; }

    .content { padding: 24px 44px 0; }

    /* Info cards */
    /* The cards are the table cells themselves so they all share the tallest one's height;
       the wrapper's negative margin cancels the outer half of the cell spacing. */
    .cards-wrap { margin: 0 -8px; }
    table.cards { width: 100%; border-collapse: separate; border-spacing: 8px 0; }
    td.card { vertical-align: top; background: #faf7f0; border: 1px solid #e4ddce; border-radius: 6px; padding: 12px 14px; }
    .card h4 { margin: 0 0 8px; font-size: 8px; font-weight: bold; letter-spacing: 1.7px; text-transform: uppercase; color: #9a7b53; }
    .card p { margin: 2px 0; font-size: 10.5px; color: #4a4740; }
    .card .name { margin: 0 0 4px; font-family: 'DejaVu Serif', serif; font-size: 14px; color: #201e1b; }
    .card .k { color: #928c7b; }

    /* Line items */
    table.items { width: 100%; border-collapse: collapse; margin-top: 22px; }
    /* the dark fill sits on the row, not on each cell, so no hairline seams show between cells */
    table.items thead tr { background: #201e1b; }
    table.items th { color: #f7f3ea; font-size: 8px; font-weight: bold; letter-spacing: 1.4px; text-transform: uppercase; text-align: left; padding: 9px 10px; }
    table.items td { padding: 11px 10px; border-bottom: 1px solid #e4ddce; vertical-align: top; font-size: 10.5px; }
    table.items tr.alt td { background: #fbf9f4; }
    .item-name { font-weight: bold; color: #201e1b; }
    .sub { margin-top: 3px; font-size: 9px; color: #928c7b; }
    .num { text-align: right; }

    /* Totals */
    table.totals { width: 46%; margin-left: 54%; margin-top: 14px; border-collapse: collapse; }
    table.totals td { padding: 6px 10px; font-size: 10.5px; color: #4a4740; }
    table.totals td.num { text-align: right; }
    table.totals tr.grand { background: #201e1b; }
    table.totals tr.grand td { padding: 11px 10px; color: #f7f3ea; font-family: 'DejaVu Serif', serif; font-size: 13px; }

    .note { margin-top: 16px; font-size: 9px; color: #928c7b; }
    .thanks { margin-top: 34px; text-align: center; font-family: 'DejaVu Serif', serif; font-style: italic; font-size: 11.5px; color: #6d6858; }
    .thanks .rule { width: 44px; height: 2px; margin: 0 auto 13px; background: #9a7b53; }

    /* Footer on every page */
    .footer { position: fixed; bottom: -56px; left: 0; right: 0; }
    .footer .line { height: 1px; margin: 0 44px; background: #e4ddce; }
    .footer p { margin: 8px 44px 16px; text-align: center; font-size: 8px; letter-spacing: 0.4px; color: #928c7b; }
</style>
