{{--
    schema.org JSON-LD for the landing page.

    Rendered here rather than from the React page for two reasons: Inertia's head
    manager owns the client side head and rewrites it on navigation, which is not
    something a crawler's snapshot should depend on, and the graph is built from
    the same PHP that answers /llms.txt - one description of the product, not two.

    Included only on `/` (see app.blade.php), because everything else is noindex.
--}}
<script type="application/ld+json">{!! App\Support\Seo\StructuredData::json() !!}</script>
