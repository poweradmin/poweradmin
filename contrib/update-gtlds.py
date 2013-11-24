import requests
import textwrap


def get_new_tlds(url=None):
    if url is None:
        url = 'http://data.iana.org/TLD/tlds-alpha-by-domain.txt'
    resp = requests.get(url)
    resp.raise_for_status()
    # The TLD list of IANA contains one TLD per line in uppercase. Lines
    # starting with a # are comments.
    return [
        tld.lower() for tld in resp.text.split('\n') if not tld.startswith('#') and tld != ''
    ]


if __name__ == '__main__':
    tlds = get_new_tlds()
    php_text = '$valid_tlds = array(%s);' % ', '.join(['"%s"' % tld for tld in tlds])
    wrapper = textwrap.TextWrapper(initial_indent='', subsequent_indent='  ',
                                   break_on_hyphens=False, width=80)
    print wrapper.fill(php_text)
