/**
 * The club's name from Settings (or the site's title), for editor placeholders
 * that show what a block's default title will say.
 */
import useEcfLmsDefaults from './use-defaults';

export default function useClubName() {
	return useEcfLmsDefaults().clubName || '';
}
