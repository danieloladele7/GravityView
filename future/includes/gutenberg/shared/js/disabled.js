import { Disabled } from '@wordpress/components';

export default function EntrySelector( { isDisabled, children } ) {
	return (
		<div className={ isDisabled ? 'disabled' : '' }>
			<Disabled isDisabled={ isDisabled }>
				{ children }
			</Disabled>
		</div>
	);
}
