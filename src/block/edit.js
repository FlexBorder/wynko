import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	Notice,
	PanelBody,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

// Fallbacks only for a build running against a stale enqueue; PHP is the source
// of truth for both (config/settings.php).
const FALLBACK_BOUNDS = { min: 1, max: 100 };
const FALLBACK_COUNT = 5;
const FALLBACK_ORDER_BY = {
	allowed: [ 'date', 'subject', 'name' ],
	default: 'date',
};
const FALLBACK_ORDER = { allowed: [ 'asc', 'desc' ], default: 'desc' };
const FALLBACK_LABEL = {
	allowed: [ 'subject', 'date', 'subject_date', 'name', 'name_date' ],
	default: 'subject',
};

// PHP owns which values exist; each layer words its own labels. These are
// functions so __() runs after the editor's translations have loaded.
const orderByLabels = () => ( {
	date: __( 'Date sent', 'wynko-for-laposta' ),
	subject: __( 'Subject', 'wynko-for-laposta' ),
	name: __( 'Campaign name', 'wynko-for-laposta' ),
} );

// Direction reads differently per sort key: "Newest first" is nonsense while
// sorting alphabetically, and "A–Z" is nonsense while sorting by date.
const orderLabels = ( orderBy ) =>
	orderBy === 'date'
		? {
				desc: __( 'Newest first', 'wynko-for-laposta' ),
				asc: __( 'Oldest first', 'wynko-for-laposta' ),
		  }
		: {
				asc: __( 'A–Z', 'wynko-for-laposta' ),
				desc: __( 'Z–A', 'wynko-for-laposta' ),
		  };

const labelFormatLabels = () => ( {
	subject: __( 'Subject', 'wynko-for-laposta' ),
	date: __( 'Date sent', 'wynko-for-laposta' ),
	subject_date: __( 'Subject and date sent', 'wynko-for-laposta' ),
	name: __( 'Campaign name', 'wynko-for-laposta' ),
	name_date: __( 'Campaign name and date sent', 'wynko-for-laposta' ),
} );

// Builds SelectControl options from the values PHP permits, so a value added
// in config/settings.php needs only a label here.
const toOptions = ( allowed, labels ) =>
	allowed
		.filter( ( value ) => labels[ value ] !== undefined )
		.map( ( value ) => ( { value, label: labels[ value ] } ) );

export default function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	const bounds = window.wynkoBlockData?.countBounds ?? FALLBACK_BOUNDS;
	const lists = window.wynkoBlockData?.lists ?? [];
	const listsError = window.wynkoBlockData?.listsError ?? false;
	const defaultCount = window.wynkoBlockData?.countDefault ?? FALLBACK_COUNT;
	const orderBy = window.wynkoBlockData?.orderBy ?? FALLBACK_ORDER_BY;
	const order = window.wynkoBlockData?.order ?? FALLBACK_ORDER;
	const labelFormat = window.wynkoBlockData?.labelFormat ?? FALLBACK_LABEL;

	// `min`/`max` on a number input are browser hints, not validation: typing
	// 101 sets the attribute. PHP clamps on render, so an unclamped value here
	// would show one number in the sidebar and another on the page.
	const onCountChange = ( value ) => {
		const count = parseInt( value, 10 );
		if ( Number.isNaN( count ) ) {
			setAttributes( { count: defaultCount } );
			return;
		}
		setAttributes( {
			count: Math.min( Math.max( count, bounds.min ), bounds.max ),
		} );
	};

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'wynko-for-laposta' ) }>
					<TextControl
						__nextHasNoMarginBottom
						label={ __(
							'Number of campaigns',
							'wynko-for-laposta'
						) }
						type="number"
						value={ attributes.count }
						onChange={ onCountChange }
						min={ bounds.min }
						max={ bounds.max }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'List', 'wynko-for-laposta' ) }
						value={ attributes.listId }
						onChange={ ( listId ) => setAttributes( { listId } ) }
						options={ [
							{
								value: '',
								label: __( 'All lists', 'wynko-for-laposta' ),
							},
							...lists,
						] }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Order by', 'wynko-for-laposta' ) }
						value={ attributes.orderBy }
						onChange={ ( value ) =>
							setAttributes( { orderBy: value } )
						}
						options={ toOptions(
							orderBy.allowed,
							orderByLabels()
						) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Direction', 'wynko-for-laposta' ) }
						value={ attributes.order }
						onChange={ ( value ) =>
							setAttributes( { order: value } )
						}
						options={ toOptions(
							order.allowed,
							orderLabels( attributes.orderBy )
						) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Item label', 'wynko-for-laposta' ) }
						value={ attributes.labelFormat }
						onChange={ ( value ) =>
							setAttributes( { labelFormat: value } )
						}
						options={ toOptions(
							labelFormat.allowed,
							labelFormatLabels()
						) }
					/>
					{ listsError && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Your lists could not be loaded, so only “All lists” is available.',
								'wynko-for-laposta'
							) }
						</Notice>
					) }
				</PanelBody>
			</InspectorControls>
			<ServerSideRender
				block="wynko/campaigns"
				attributes={ attributes }
			/>
		</div>
	);
}
