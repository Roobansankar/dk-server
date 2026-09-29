{{-- Shared look of the bill PDFs (appointment bill + shop order bill). dompdf: tables and floats only — no flex/grid. --}}
<style>
    /* The top margin only shows from page 2 on (the header pulls itself flush to the top of page 1);
       the bottom margin keeps table rows clear of the footer. */
    @page { margin: 30px 0 98px 0; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { margin: 0; color: #201e1b; font-size: 10.5px; line-height: 1.45; }

    /* Header: a solid dark band carrying the logo and the invoice details. */
    .top { margin-top: -30px; }
    .band { background: #14120f; padding: 26px 44px 22px; }
    .band table { width: 100%; border-collapse: collapse; }
    .band td { padding: 0; vertical-align: top; }
    .logo { width: 128px; }
    .tagline { margin-top: 2px; font-size: 8px; font-weight: bold; letter-spacing: 2.6px; color: #c9a668; text-transform: uppercase; }
    .doc-title { font-family: 'DejaVu Serif', serif; font-size: 27px; letter-spacing: 6px; text-transform: uppercase; text-align: right; color: #e8dcc0; }
    .doc-ref { margin-top: 9px; text-align: right; font-size: 9.5px; letter-spacing: 0.3px; color: #c4baa1; }
    .badge { display: inline-block; margin-top: 12px; padding: 5px 15px; border-radius: 12px; border: 1px solid #3f8f6a; background: rgba(64,143,106,0.16); color: #5fcb9a; font-size: 8.5px; font-weight: bold; letter-spacing: 1.6px; }
    .badge.pending { border-color: #b3862e; background: rgba(179,134,46,0.16); color: #dcab52; }
    .right { text-align: right; }

    .content { padding: 26px 44px 0; }

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
    table.items thead tr { background: #14120f; }
    table.items th { color: #f7f3ea; font-size: 8px; font-weight: bold; letter-spacing: 1.4px; text-transform: uppercase; text-align: left; padding: 9px 10px; }
    table.items td { padding: 11px 10px; border-bottom: 1px solid #e4ddce; vertical-align: top; font-size: 10.5px; }
    table.items tr.alt td { background: #fbf9f4; }
    .item-name { font-weight: bold; color: #201e1b; }
    .sub { margin-top: 3px; font-size: 9px; color: #928c7b; }
    .num { text-align: right; }
    /* .num on a <th> must out-rank "table.items th"'s own text-align, so headers
       line up directly over the right-aligned numbers beneath them. */
    table.items th.num { text-align: right; }

    /* Payment summary (right) + extras (left), side by side */
    table.bottom-grid { width: 100%; border-collapse: collapse; margin-top: 20px; }
    table.bottom-grid > tr > td { vertical-align: top; padding: 0; }
    td.summary-col { width: 54%; padding-left: 16px; }
    td.extras-col { width: 46%; }

    table.totals { width: 100%; border-collapse: collapse; }
    table.totals td { padding: 6px 2px; font-size: 10.5px; color: #4a4740; }
    table.totals td.num { text-align: right; }
    table.totals tr.grand { background: #14120f; }
    table.totals tr.grand td { padding: 12px 10px; color: #f7f3ea; font-family: 'DejaVu Serif', serif; font-size: 13.5px; }
    table.totals tr.grand td:first-child { border-radius: 4px 0 0 4px; }
    table.totals tr.grand td:last-child { border-radius: 0 4px 4px 0; }

    /* Extras: stacked boxes to the left of the totals */
    .box { border-radius: 6px; padding: 11px 13px; font-size: 9px; line-height: 1.55; }
    .box + .box { margin-top: 10px; }
    .box-light { background: #faf3e4; border: 1px solid #ecdfc3; color: #4a4740; }
    .box-light .box-title { display: block; margin-bottom: 3px; font-size: 8px; font-weight: bold; letter-spacing: 1.1px; text-transform: uppercase; color: #9a7b53; }
    .box-light .box-strong { font-weight: bold; color: #201e1b; }
    .box-dark { background: #14120f; color: #d9d2c2; }
    .box-dark .box-title { display: block; margin-bottom: 3px; font-size: 9.5px; font-weight: bold; color: #e8dcc0; font-family: 'DejaVu Serif', serif; letter-spacing: 0.3px; }
    .box-follow { border-radius: 6px; padding: 10px 12px; background: #faf7f0; }
    .box-follow p { margin: 3px 0; font-size: 9px; color: #4a4740; }
    .box-follow .k { display: inline-block; width: 13px; color: #9a7b53; font-weight: bold; }

    .note { margin-top: 16px; font-size: 9px; color: #928c7b; }
    .thanks { margin-top: 30px; text-align: center; }
    .thanks .rule { width: 44px; height: 2px; margin: 0 auto 13px; background: #9a7b53; }
    .thanks .line1 { font-family: 'DejaVu Serif', serif; font-style: italic; font-size: 12.5px; color: #4a4740; }
    .thanks .line2 { margin-top: 5px; font-size: 8px; letter-spacing: 3px; text-transform: uppercase; color: #9a7b53; }

    /* Footer on every page. The negative bottom exactly cancels the @page
       bottom margin above, so the band sits flush with the true page edge
       instead of leaving a sliver of white below it. */
    .footer { position: fixed; bottom: -98px; left: 0; right: 0; background: #14120f; color: #e2dac8; }
    .footer table { width: 100%; border-collapse: collapse; }
    .footer td { padding: 14px 44px 0; font-size: 8.5px; vertical-align: top; line-height: 1.5; }
    .footer .info { width: 70%; }
    .footer .script { width: 30%; white-space: nowrap; text-align: right; font-family: 'DejaVu Serif', serif; font-style: italic; font-size: 11px; color: #e8dcc0; }
    .footer .terms { padding: 9px 44px 14px; font-size: 7.5px; color: #c4baa1; border-top: 1px solid #3a362f; margin-top: 10px; }
</style>
