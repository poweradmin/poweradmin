<?php
namespace RobRichards\XMLSecLibs;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Exception;
use RobRichards\XMLSecLibs\Utils\XPath as XPath;

/**
 * xmlseclibs.php
 *
 * Copyright (c) 2007-2026, Robert Richards <rrichards@cdatazone.org>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Robert Richards nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @author    Robert Richards <rrichards@cdatazone.org>
 * @copyright 2007-2026 Robert Richards <rrichards@cdatazone.org>
 * @license   http://www.opensource.org/licenses/bsd-license.php  BSD License
 */

class XMLSecEnc
{
    const template = "<xenc:EncryptedData xmlns:xenc='http://www.w3.org/2001/04/xmlenc#'>
   <xenc:CipherData>
      <xenc:CipherValue></xenc:CipherValue>
   </xenc:CipherData>
</xenc:EncryptedData>";

    const template_NOWS = "<xenc:EncryptedData xmlns:xenc='http://www.w3.org/2001/04/xmlenc#'><xenc:CipherData><xenc:CipherValue></xenc:CipherValue></xenc:CipherData></xenc:EncryptedData>";

    const Element = 'http://www.w3.org/2001/04/xmlenc#Element';
    const Content = 'http://www.w3.org/2001/04/xmlenc#Content';
    const URI = 3;
    const XMLENCNS = 'http://www.w3.org/2001/04/xmlenc#';

    /** Maximum depth of EncryptedKey/RetrievalMethod resolution (DoS protection). */
    const MAX_KEYINFO_DEPTH = 10;

    /**
     * Recommended key-transport (asymmetric) algorithms. RSA-1.5 is excluded:
     * it is vulnerable to Bleichenbacher / XML-Encryption backward-compatibility
     * attacks and must be opted into explicitly.
     */
    const DEFAULT_KEY_ALGORITHMS = array(
        XMLSecurityKey::RSA_OAEP,
        XMLSecurityKey::RSA_OAEP_MGF1P,
    );

    /**
     * Recommended data-encryption (symmetric) algorithms. Authenticated GCM
     * modes only; unauthenticated CBC modes are excluded because they are
     * malleable and prone to padding-oracle attacks.
     */
    const DEFAULT_DATA_ALGORITHMS = array(
        XMLSecurityKey::AES128_GCM,
        XMLSecurityKey::AES192_GCM,
        XMLSecurityKey::AES256_GCM,
    );

    /**
     * Allowlist of acceptable key-transport (asymmetric) algorithm URIs.
     * When null (default) no allowlist restriction is applied, preserving
     * backward compatibility. Note that RSA-1.5 is additionally governed by
     * $allowRSA15KeyTransport regardless of this list.
     * @var array<int, string>|null
     */
    public $allowedKeyAlgorithms = null;

    /**
     * Allowlist of acceptable data-encryption (symmetric) algorithm URIs.
     * When null (default) no allowlist restriction is applied.
     * @var array<int, string>|null
     */
    public $allowedDataAlgorithms = null;

    /**
     * Whether RSA-1.5 (PKCS#1 v1.5) key transport is permitted on decryption.
     *
     * Denied by default: an attacker who chooses the EncryptedKey algorithm can
     * otherwise mount a Bleichenbacher adaptive chosen-ciphertext attack against
     * the private key. Set to true only for legacy interoperability, ideally
     * behind additional oracle protections.
     * @var bool
     */
    public $allowRSA15KeyTransport = false;

    /** @var null|DOMDocument */
    private $encdoc = null;

    /** @var null|DOMNode  */
    private $rawNode = null;

    /** @var null|string */
    public $type = null;

    /** @var null|DOMElement */
    public $encKey = null;

    /** @var null|string Optional Id when embedding EncryptedKey in a Security header */
    public $guid = null;

    /**
     * @var array<string, array{node: DOMNode, type: string, encnode: DOMDocument|DOMElement|DOMNode, refuri: string}>
     */
    private $references = array();

    /** @var string */
    private $activeTemplate;

    /**
     * @param null|array{stripWhitespace?: bool} $options Optional flags; use 'stripWhitespace' => true for a compact template
     */
    public function __construct($options=null)
    {
        $stripWhitespace = false;
        if (is_array($options)) {
            $stripWhitespace = !isset($options['stripWhitespace']) ? false : (bool) $options['stripWhitespace'];
        }
        $this->activeTemplate = $stripWhitespace ? self::template_NOWS : self::template;
        $this->_resetTemplate();
    }

    /**
     * Restore pre-4.0 interoperability defaults for XML Encryption decryption.
     *
     * Use this only when you must decrypt payloads that use RSA-1.5 key
     * transport. Prefer migrating senders to RSA-OAEP (and AES-GCM) and then
     * removing the call.
     *
     * This does NOT undo 4.0 oracle / XXE hardening that is always enforced
     * (uniform decryption error messages, DOCTYPE rejection in decrypted XML,
     * EncryptedKey recursion depth bound, ISO 10126 pad-length validation).
     *
     * @return $this
     */
    public function enableLegacyMode()
    {
        $this->allowRSA15KeyTransport = true;
        return $this;
    }

    /**
     * @return void
     */
    private function _resetTemplate()
    {
        $this->encdoc = new DOMDocument();
        $this->encdoc->loadXML($this->activeTemplate);
    }

    /**
     * @param string $name
     * @param DOMNode $node
     * @param string $type
     * @return void
     * @throws Exception
     */
    public function addReference($name, $node, $type)
    {
        if (! $node instanceof DOMNode) {
            throw new Exception('$node is not of type DOMNode');
        }
        $curencdoc = $this->encdoc;
        $this->_resetTemplate();
        $encdoc = $this->encdoc;
        $this->encdoc = $curencdoc;
        if ($encdoc === null || $encdoc->documentElement === null) {
            throw new Exception('Error locating EncryptedData element within template');
        }
        $refuri = XMLSecurityDSig::generateGUID();
        $element = $encdoc->documentElement;
        $element->setAttribute("Id", $refuri);
        $this->references[$name] = array("node" => $node, "type" => $type, "encnode" => $encdoc, "refuri" => $refuri);
    }

    /**
     * @param DOMNode $node
     * @return void
     */
    public function setNode($node)
    {
        $this->rawNode = $node;
    }

    /**
     * Encrypt the selected node with the given key.
     *
     * @param XMLSecurityKey $objKey  The encryption key and algorithm.
     * @param bool           $replace Whether the encrypted node should be replaced in the original tree. Default is true.
     * @throws Exception
     *
     * @return DOMElement|DOMDocument  The <xenc:EncryptedData> element, or the encryption
     *                                 document when replacing a document node.
     */
    public function encryptNode($objKey, $replace = true)
    {
        $data = '';
        if (empty($this->rawNode)) {
            throw new Exception('Node to encrypt has not been set');
        }
        if (! $objKey instanceof XMLSecurityKey) {
            throw new Exception('Invalid Key');
        }
        if ($this->encdoc === null) {
            throw new Exception('Encryption template has not been initialized');
        }
        $doc = $this->rawNode->ownerDocument;
        if ($doc === null) {
            if ($this->rawNode instanceof DOMDocument) {
                $doc = $this->rawNode;
            } else {
                throw new Exception('Cannot resolve owner document for encryption');
            }
        }
        $xPath = new DOMXPath($this->encdoc);
        $objList = $xPath->query('/xenc:EncryptedData/xenc:CipherData/xenc:CipherValue');
        if ($objList === false) {
            throw new Exception('Error locating CipherValue element within template');
        }
        $cipherValue = $objList->item(0);
        if ($cipherValue == null) {
            throw new Exception('Error locating CipherValue element within template');
        }
        $root = $this->encdoc->documentElement;
        if ($root === null) {
            throw new Exception('Error locating EncryptedData element within template');
        }
        switch ($this->type) {
            case (self::Element):
                $data = $doc->saveXML($this->rawNode);
                if ($data === false) {
                    throw new Exception('Error serializing node for encryption');
                }
                $root->setAttribute('Type', self::Element);
                break;
            case (self::Content):
                $children = $this->rawNode->childNodes;
                foreach ($children AS $child) {
                    $childXml = $doc->saveXML($child);
                    if ($childXml === false) {
                        throw new Exception('Error serializing node for encryption');
                    }
                    $data .= $childXml;
                }
                $root->setAttribute('Type', self::Content);
                break;
            default:
                throw new Exception('Type is currently not supported');
        }

        $encMethod = $this->encdoc->createElementNS(self::XMLENCNS, 'xenc:EncryptionMethod');
        if ($encMethod === false) {
            throw new Exception('Error creating EncryptionMethod element');
        }
        $encMethod->setAttribute('Algorithm', $objKey->getAlgorithm());
        $root->appendChild($encMethod);
        $cipherData = $cipherValue->parentNode;
        if ($cipherData === null || $cipherData->parentNode === null) {
            throw new Exception('Error locating CipherData element within template');
        }
        $cipherData->parentNode->insertBefore($encMethod, $cipherData->parentNode->firstChild);

        $encrypted = $objKey->encryptData($data);
        if (! is_string($encrypted)) {
            throw new Exception('Failure encrypting Data');
        }
        $strEncrypt = base64_encode($encrypted);
        $value = $this->encdoc->createTextNode($strEncrypt);
        $cipherValue->appendChild($value);

        if ($replace) {
            switch ($this->type) {
                case (self::Element):
                    if ($this->rawNode->nodeType == XML_DOCUMENT_NODE) {
                        return $this->encdoc;
                    }
                    $ownerDoc = $this->rawNode->ownerDocument;
                    if ($ownerDoc === null) {
                        throw new Exception('Cannot resolve owner document for encryption');
                    }
                    $importEnc = $ownerDoc->importNode($root, true);
                    if (! $importEnc instanceof DOMElement) {
                        throw new Exception('Error importing EncryptedData element');
                    }
                    if ($this->rawNode->parentNode === null) {
                        throw new Exception('Cannot replace node without a parent');
                    }
                    $this->rawNode->parentNode->replaceChild($importEnc, $this->rawNode);
                    return $importEnc;
                case (self::Content):
                    $ownerDoc = $this->rawNode->ownerDocument;
                    if ($ownerDoc === null) {
                        throw new Exception('Cannot resolve owner document for encryption');
                    }
                    $importEnc = $ownerDoc->importNode($root, true);
                    if (! $importEnc instanceof DOMElement) {
                        throw new Exception('Error importing EncryptedData element');
                    }
                    while ($this->rawNode->firstChild) {
                        $this->rawNode->removeChild($this->rawNode->firstChild);
                    }
                    $this->rawNode->appendChild($importEnc);
                    return $importEnc;
                default:
                    throw new Exception('Type is currently not supported');
            }
        }
        return $root;
    }

    /**
     * @param XMLSecurityKey $objKey
     * @return void
     * @throws Exception
     */
    public function encryptReferences($objKey)
    {
        $curRawNode = $this->rawNode;
        $curType = $this->type;
        foreach ($this->references AS $name => $reference) {
            if (! $reference["encnode"] instanceof DOMDocument) {
                throw new Exception('Encryption template has not been initialized');
            }
            $this->encdoc = $reference["encnode"];
            $this->rawNode = $reference["node"];
            $this->type = $reference["type"];
            try {
                $encNode = $this->encryptNode($objKey);
                $this->references[$name]["encnode"] = $encNode;
            } catch (Exception $e) {
                $this->rawNode = $curRawNode;
                $this->type = $curType;
                throw $e;
            }
        }
        $this->rawNode = $curRawNode;
        $this->type = $curType;
    }

    /**
     * Retrieve the CipherValue text from this encrypted node.
     *
     * @throws Exception
     * @return string|null  The Ciphervalue text, or null if no CipherValue is found.
     */
    public function getCipherValue()
    {
        if (empty($this->rawNode)) {
            throw new Exception('Node to decrypt has not been set');
        }

        $doc = $this->rawNode->ownerDocument;
        if ($doc === null) {
            if ($this->rawNode instanceof DOMDocument) {
                $doc = $this->rawNode;
            } else {
                throw new Exception('Cannot resolve owner document for decryption');
            }
        }
        $xPath = new DOMXPath($doc);
        $xPath->registerNamespace('xmlencr', self::XMLENCNS);
        /* Only handles embedded content right now and not a reference */
        $query = "./xmlencr:CipherData/xmlencr:CipherValue";
        $nodeset = $xPath->query($query, $this->rawNode);
        if ($nodeset === false) {
            return null;
        }
        $node = $nodeset->item(0);

        if (!$node) {
                return null;
        }

        /* nodeValue may be null for empty text; invalid base64 becomes empty string in non-strict mode */
        return base64_decode((string) $node->nodeValue);
    }

    /**
     * Decrypt this encrypted node.
     *
     * The behaviour of this function depends on the value of $replace.
     * If $replace is false, we will return the decrypted data as a string.
     * If $replace is true, we will insert the decrypted element(s) into the
     * document, and return the decrypted element(s).
     *
     * @param XMLSecurityKey $objKey  The decryption key that should be used when decrypting the node.
     * @param boolean        $replace Whether we should replace the encrypted node in the XML document with the decrypted data. The default is true.
     *
     * @return string|DOMNode|DOMDocument  The decrypted data.
     */
    public function decryptNode($objKey, $replace=true)
    {
        if (! $objKey instanceof XMLSecurityKey) {
            throw new Exception('Invalid Key');
        }

        $this->enforceAlgorithmPolicy($objKey);

        $rawNode = $this->rawNode;
        if ($rawNode === null) {
            throw new Exception('Node to decrypt has not been set');
        }

        $encryptedData = $this->getCipherValue();
        if ($encryptedData) {
            $decrypted = $objKey->decryptData($encryptedData);
            if (! is_string($decrypted)) {
                throw new Exception('Failure decrypting Data');
            }
            if ($replace) {
                switch ($this->type) {
                    case (self::Element):
                        $newdoc = self::parseDecryptedXML($decrypted);
                        if ($rawNode->nodeType == XML_DOCUMENT_NODE) {
                            return $newdoc;
                        }
                        $ownerDoc = $rawNode->ownerDocument;
                        if ($ownerDoc === null || $newdoc->documentElement === null) {
                            throw new Exception('Error parsing decrypted XML');
                        }
                        $importEnc = $ownerDoc->importNode($newdoc->documentElement, true);
                        if (! $importEnc instanceof DOMElement) {
                            throw new Exception('Error importing decrypted XML');
                        }
                        if ($rawNode->parentNode === null) {
                            throw new Exception('Cannot replace node without a parent');
                        }
                        $rawNode->parentNode->replaceChild($importEnc, $rawNode);
                        return $importEnc;
                    case (self::Content):
                        if ($rawNode instanceof DOMDocument) {
                            $doc = $rawNode;
                        } else {
                            $doc = $rawNode->ownerDocument;
                        }
                        if (! $doc instanceof DOMDocument) {
                            throw new Exception('Cannot resolve owner document for decryption');
                        }
                        $tmp = self::parseDecryptedXML('<root>'.$decrypted.'</root>');
                        $tmpRoot = $tmp->documentElement;
                        if ($tmpRoot === null) {
                            throw new Exception('Error parsing decrypted XML');
                        }
                        $newFrag = $doc->createDocumentFragment();
                        foreach (iterator_to_array($tmpRoot->childNodes) as $child) {
                            $newFrag->appendChild($doc->importNode($child, true));
                        }
                        $parent = $rawNode->parentNode;
                        if ($parent === null) {
                            throw new Exception('Cannot replace node without a parent');
                        }
                        $parent->replaceChild($newFrag, $rawNode);
                        return $parent;
                    default:
                        return $decrypted;
                }
            } else {
                return $decrypted;
            }
        } else {
            throw new Exception("Cannot locate encrypted data");
        }
    }

    /**
     * Safely parse decrypted XML.
     *
     * External entity resolution is disabled (LIBXML_NONET and the parser
     * default of not expanding entities), and any DOCTYPE is rejected. XML
     * Encryption payloads never legitimately carry a DOCTYPE, so refusing one
     * closes off entity-expansion (billion laughs) and XXE vectors in content
     * that an attacker who knows the key could otherwise craft.
     *
     * @param string $xml
     * @return DOMDocument
     * @throws Exception
     */
    private static function parseDecryptedXML($xml)
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false || $doc->documentElement === null) {
            throw new Exception('Error parsing decrypted XML');
        }
        if ($doc->doctype !== null) {
            throw new Exception('Decrypted XML must not contain a DOCTYPE');
        }
        return $doc;
    }

    /**
     * Encrypt the XMLSecurityKey
     *
     * @param XMLSecurityKey $srcKey
     * @param XMLSecurityKey $rawKey
     * @param bool $append
     * @return void
     * @throws Exception
     */
    public function encryptKey($srcKey, $rawKey, $append=true)
    {
        if ((! $srcKey instanceof XMLSecurityKey) || (! $rawKey instanceof XMLSecurityKey)) {
            throw new Exception('Invalid Key');
        }
        if ($this->encdoc === null) {
            throw new Exception('Encryption template has not been initialized');
        }
        if (! is_string($rawKey->key)) {
            throw new Exception('Key is missing data to perform the encryption');
        }
        $encryptedKey = $srcKey->encryptData($rawKey->key);
        if (! is_string($encryptedKey)) {
            throw new Exception('Failure encrypting Data');
        }
        $strEncKey = base64_encode($encryptedKey);
        $root = $this->encdoc->documentElement;
        if ($root === null) {
            throw new Exception('Error locating EncryptedData element within template');
        }
        $encKey = $this->encdoc->createElementNS(self::XMLENCNS, 'xenc:EncryptedKey');
        if ($encKey === false) {
            throw new Exception('Error creating EncryptedKey element');
        }
        if ($append) {
            $keyInfo = $this->encdoc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'dsig:KeyInfo');
            if ($keyInfo === false) {
                throw new Exception('Error creating KeyInfo element');
            }
            $root->insertBefore($keyInfo, $root->firstChild);
            $keyInfo->appendChild($encKey);
        } else {
            $this->encKey = $encKey;
        }
        $encMethod = $this->encdoc->createElementNS(self::XMLENCNS, 'xenc:EncryptionMethod');
        if ($encMethod === false) {
            throw new Exception('Error creating EncryptionMethod element');
        }
        $encMethod->setAttribute('Algorithm', $srcKey->getAlgorithm());
        $encKey->appendChild($encMethod);
        if (! empty($srcKey->name)) {
            $keyInfo = $this->encdoc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'dsig:KeyInfo');
            if ($keyInfo === false) {
                throw new Exception('Error creating KeyInfo element');
            }
            $encKey->appendChild($keyInfo);
            $keyInfo->appendChild($this->encdoc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'dsig:KeyName', $srcKey->name));
        }
        $cipherData = $this->encdoc->createElementNS(self::XMLENCNS, 'xenc:CipherData');
        if ($cipherData === false) {
            throw new Exception('Error creating CipherData element');
        }
        $encKey->appendChild($cipherData);
        $cipherData->appendChild($this->encdoc->createElementNS(self::XMLENCNS, 'xenc:CipherValue', $strEncKey));
        if (count($this->references) > 0) {
            $refList = $this->encdoc->createElementNS(self::XMLENCNS, 'xenc:ReferenceList');
            if ($refList === false) {
                throw new Exception('Error creating ReferenceList element');
            }
            $encKey->appendChild($refList);
            foreach ($this->references AS $name => $reference) {
                $refuri = $reference["refuri"];
                $dataRef = $this->encdoc->createElementNS(self::XMLENCNS, 'xenc:DataReference');
                if ($dataRef === false) {
                    throw new Exception('Error creating DataReference element');
                }
                $dataRef->setAttribute("URI", '#' . $refuri);
                $refList->appendChild($dataRef);
            }
        }
        return;
    }

    /**
     * @param XMLSecurityKey $encKey
     * @return string
     * @throws Exception
     */
    public function decryptKey($encKey)
    {
        if (! $encKey->isEncrypted) {
            throw new Exception("Key is not Encrypted");
        }
        if (empty($encKey->key)) {
            throw new Exception("Key is missing data to perform the decryption");
        }
        $decrypted = $this->decryptNode($encKey, false);
        if (! is_string($decrypted)) {
            throw new Exception('Failure decrypting Data');
        }
        return $decrypted;
    }

    /**
     * @param DOMNode $element
     * @return DOMNode|null
     */
    public function locateEncryptedData($element)
    {
        if ($element instanceof DOMDocument) {
            $doc = $element;
        } else {
            $doc = $element->ownerDocument;
        }
        if ($doc) {
            $xpath = new DOMXPath($doc);
            $query = "//*[local-name()='EncryptedData' and namespace-uri()='".self::XMLENCNS."']";
            $nodeset = $xpath->query($query);
            if ($nodeset === false) {
                return null;
            }
            return $nodeset->item(0);
        }
        return null;
    }

    /**
     * Returns the key from the DOM
     * @param null|DOMNode $node
     * @return null|XMLSecurityKey
     */
    public function locateKey($node=null)
    {
        if (empty($node)) {
            $node = $this->rawNode;
        }
        if (! $node instanceof DOMNode) {
            return null;
        }
        if ($doc = $node->ownerDocument) {
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('xmlsecenc', self::XMLENCNS);
            $query = ".//xmlsecenc:EncryptionMethod";
            $nodeset = $xpath->query($query, $node);
            if ($nodeset === false) {
                return null;
            }
            if ($encmeth = $nodeset->item(0)) {
                if (! $encmeth instanceof DOMElement) {
                    return null;
                }
                $attrAlgorithm = $encmeth->getAttribute("Algorithm");
                try {
                    $objKey = new XMLSecurityKey($attrAlgorithm, array('type' => 'private'));
                } catch (Exception $e) {
                    return null;
                }
                $this->enforceAlgorithmPolicy($objKey);
                return $objKey;
            }
        }
        return null;
    }

    /**
     * Enforce the configured algorithm policy for a key located in the document.
     *
     * Fails closed (throws) when the document-selected algorithm is not
     * permitted. This is what prevents an attacker from downgrading key
     * transport to RSA-1.5 or selecting an algorithm outside a caller's
     * allowlist.
     *
     * @param XMLSecurityKey $objKey
     * @return void
     * @throws Exception
     */
    private function enforceAlgorithmPolicy($objKey)
    {
        if (! $objKey instanceof XMLSecurityKey) {
            return;
        }
        $algorithm = $objKey->getAlgorithm();
        if (! is_string($algorithm)) {
            throw new Exception('Invalid key algorithm');
        }
        if ($objKey->isSymmetricCipher()) {
            if ($this->allowedDataAlgorithms !== null
                && ! in_array($algorithm, $this->allowedDataAlgorithms, true)) {
                throw new Exception("Data encryption algorithm is not allowed: '$algorithm'");
            }
            return;
        }
        /* Asymmetric key-transport algorithm. */
        if ($algorithm === XMLSecurityKey::RSA_1_5 && ! $this->allowRSA15KeyTransport) {
            throw new Exception(
                'RSA-1.5 key transport is disabled (Bleichenbacher risk); '
                . 'set allowRSA15KeyTransport = true to opt in'
            );
        }
        if ($this->allowedKeyAlgorithms !== null
            && ! in_array($algorithm, $this->allowedKeyAlgorithms, true)) {
            throw new Exception("Key transport algorithm is not allowed: '$algorithm'");
        }
    }

    /**
     * @param null|XMLSecurityKey $objBaseKey
     * @param null|DOMNode $node
     * @param int $depth
     * @param bool $allowRSA15
     * @return null|XMLSecurityKey
     * @throws Exception
     */
    public static function staticLocateKeyInfo($objBaseKey=null, $node=null, $depth=0, $allowRSA15=false)
    {
        if (empty($node) || (! $node instanceof DOMNode)) {
            return null;
        }
        /*
         * Bound the EncryptedKey / RetrievalMethod resolution chain. A crafted
         * document can otherwise reference EncryptedKey elements in a cycle
         * (e.g. a RetrievalMethod pointing back at its own EncryptedKey),
         * causing unbounded recursion and memory exhaustion (DoS).
         */
        if ($depth > self::MAX_KEYINFO_DEPTH) {
            throw new Exception('EncryptedKey reference chain is too deep');
        }
        $doc = $node->ownerDocument;
        if (!$doc) {
            return null;
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('xmlsecenc', self::XMLENCNS);
        $xpath->registerNamespace('xmlsecdsig', XMLSecurityDSig::XMLDSIGNS);
        $query = "./xmlsecdsig:KeyInfo";
        $nodeset = $xpath->query($query, $node);
        if ($nodeset === false) {
            return $objBaseKey;
        }
        $encmeth = $nodeset->item(0);
        if (!$encmeth) {
            /* No KeyInfo in EncryptedData / EncryptedKey. */
            return $objBaseKey;
        }

        foreach ($encmeth->childNodes AS $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            switch ($child->localName) {
                case 'KeyName':
                    if (! empty($objBaseKey)) {
                        $objBaseKey->name = $child->nodeValue;
                    }
                    break;
                case 'KeyValue':
                    foreach ($child->childNodes AS $keyval) {
                        if (! $keyval instanceof DOMElement) {
                            continue;
                        }
                        switch ($keyval->localName) {
                            case 'DSAKeyValue':
                                throw new Exception("DSAKeyValue currently not supported");
                            case 'RSAKeyValue':
                                $modulus = null;
                                $exponent = null;
                                if ($modulusNode = $keyval->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'Modulus')->item(0)) {
                                    $modulus = base64_decode((string) $modulusNode->nodeValue);
                                }
                                if ($exponentNode = $keyval->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'Exponent')->item(0)) {
                                    $exponent = base64_decode((string) $exponentNode->nodeValue);
                                }
                                if (empty($modulus) || empty($exponent)) {
                                    throw new Exception("Missing Modulus or Exponent");
                                }
                                if ($objBaseKey === null) {
                                    throw new Exception('Cannot load KeyValue without a base key');
                                }
                                $publicKey = XMLSecurityKey::convertRSA($modulus, $exponent);
                                $objBaseKey->loadKey($publicKey);
                                break;
                            case 'ECKeyValue':
                                $namedCurveNode = $keyval->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIG11NS, 'NamedCurve')->item(0);
                                $publicKeyNode = $keyval->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIG11NS, 'PublicKey')->item(0);
                                if (! $namedCurveNode instanceof DOMElement || ! $publicKeyNode) {
                                    /* ECParameters (explicit curve parameters) are not supported. */
                                    throw new Exception("Missing NamedCurve or PublicKey");
                                }
                                $curveURI = $namedCurveNode->getAttribute('URI');
                                if (strncmp($curveURI, 'urn:oid:', 8) !== 0) {
                                    throw new Exception("Unsupported NamedCurve URI");
                                }
                                $point = base64_decode(preg_replace('/\s+/', '', (string) $publicKeyNode->textContent), true);
                                if (empty($point)) {
                                    throw new Exception("Invalid ECKeyValue PublicKey");
                                }
                                if ($objBaseKey === null) {
                                    throw new Exception('Cannot load KeyValue without a base key');
                                }
                                $publicKey = XMLSecurityKey::convertEC(substr($curveURI, 8), $point, $objBaseKey->allowedECCurves);
                                $objBaseKey->loadKey($publicKey);
                                break;
                        }
                    }
                    break;
                case 'RetrievalMethod':
                    $type = $child->getAttribute('Type');
                    if ($type !== 'http://www.w3.org/2001/04/xmlenc#EncryptedKey') {
                        /* Unsupported key type. */
                        break;
                    }
                    $uri = $child->getAttribute('URI');
                    if ($uri === '' || $uri[0] !== '#') {
                        /* URI not a reference - unsupported. */
                        break;
                    }
                    $id = substr($uri, 1);

                    $query = '//xmlsecenc:EncryptedKey[@Id="'.XPath::filterAttrValue($id, XPath::DOUBLE_QUOTE).'"]';
                    $keyNodes = $xpath->query($query);
                    if ($keyNodes === false || ! (($keyElement = $keyNodes->item(0)) instanceof DOMElement)) {
                        throw new Exception("Unable to locate EncryptedKey with @Id='$id'.");
                    }

                    return XMLSecurityKey::fromEncryptedKeyElement($keyElement, $depth + 1, $allowRSA15);
                case 'EncryptedKey':
                    return XMLSecurityKey::fromEncryptedKeyElement($child, $depth + 1, $allowRSA15);
                case 'X509Data':
                    $x509certNodes = $child->getElementsByTagName('X509Certificate');
                    if ($x509certNodes->length > 0) {
                        $x509certNode = $x509certNodes->item(0);
                        if ($x509certNode === null) {
                            break;
                        }
                        $x509cert = $x509certNode->textContent;
                        $x509cert = str_replace(array("\r", "\n", " ", "\t"), "", $x509cert);
                        $x509cert = "-----BEGIN CERTIFICATE-----\n".chunk_split($x509cert, 64, "\n")."-----END CERTIFICATE-----\n";
                        if ($objBaseKey === null) {
                            throw new Exception('Cannot load X509Data without a base key');
                        }
                        $objBaseKey->loadKey($x509cert, false, true);
                    }
                    break;
            }
        }
        return $objBaseKey;
    }

    /**
     * @param null|XMLSecurityKey $objBaseKey
     * @param null|DOMNode $node
     * @return null|XMLSecurityKey
     */
    public function locateKeyInfo($objBaseKey=null, $node=null)
    {
        if (empty($node)) {
            $node = $this->rawNode;
        }
        return self::staticLocateKeyInfo($objBaseKey, $node, 0, $this->allowRSA15KeyTransport);
    }
}
