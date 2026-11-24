<div data-pricing-decimal-list style="max-height: 60vh; overflow-y: auto;">
    <table style="width: 100%; table-layout: fixed; border-collapse: collapse; font-size: .875rem;">
        <thead>
            <tr>
                <th scope="col" style="padding: .25rem .5rem; text-align: left;">Oszlop</th>
                <th scope="col" style="width: 10rem; padding: .25rem .5rem; text-align: right;">Tizedesjegyek</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($getChildSchema()->getComponents() as $field)
                <tr data-pricing-decimal-row="{{ $field->getName() }}">
                    <th scope="row" style="padding: .2rem .5rem; text-align: left; font-weight: 400; overflow-wrap: anywhere; vertical-align: middle;">
                        {{ $field->getLabel() }}
                    </th>
                    <td style="padding: .2rem .5rem; vertical-align: middle;">
                        {{ $field }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
