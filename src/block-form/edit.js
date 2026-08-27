import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	ExternalLink,
	Notice,
	PanelBody,
	SelectControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

// PHP is the source of truth; this is only what a build against a stale
// enqueue falls back to.
const FALLBACK_DATA = { forms: [], editUrls: {}, listUrl: '' };

export default function Edit( { attributes, setAttributes } ) {
	const { formId } = attributes;
	const {
		forms = [],
		editUrls = {},
		listUrl = '',
	} = window.wynkoFormBlockData ?? FALLBACK_DATA;

	const options = [
		{
			value: '',
			label: __( '— Choose a form —', 'wynko-for-laposta' ),
		},
		...forms,
	];

	const chosen = forms.some( ( form ) => form.value === formId );

	// Both cases render nothing on the front end, so the canvas would otherwise
	// say only "Block rendered as empty" — true, and no help at all. The notice
	// says what is missing and links to the screen that fixes it.
	const problem =
		forms.length === 0
			? __(
					'No published signup forms yet. Create one under Wynko → Signup forms.',
					'wynko-for-laposta'
			  )
			: __(
					'The chosen form is no longer published, so nothing is shown. Pick another one in the block settings.',
					'wynko-for-laposta'
			  );

	const broken = forms.length === 0 || ( formId !== '' && ! chosen );

	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Signup form', 'wynko-for-laposta' ) }>
					{ forms.length === 0 && (
						<Notice status="warning" isDismissible={ false }>
							{ problem }
						</Notice>
					) }
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Form', 'wynko-for-laposta' ) }
						value={ formId }
						options={ options }
						onChange={ ( value ) =>
							setAttributes( { formId: value } )
						}
					/>
					{ chosen && editUrls[ formId ] && (
						<ExternalLink href={ editUrls[ formId ] }>
							{ __( 'Edit this form', 'wynko-for-laposta' ) }
						</ExternalLink>
					) }
				</PanelBody>
			</InspectorControls>
			{ broken ? (
				<Notice status="warning" isDismissible={ false }>
					{ problem }{ ' ' }
					{ listUrl && (
						<ExternalLink href={ listUrl }>
							{ __( 'Open signup forms', 'wynko-for-laposta' ) }
						</ExternalLink>
					) }
				</Notice>
			) : (
				<ServerSideRender
					block="wynko/form"
					attributes={ attributes }
				/>
			) }
		</div>
	);
}
