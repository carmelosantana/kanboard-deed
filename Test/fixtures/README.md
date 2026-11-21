# Fixtures

Recorded 2026-11-21 from public RDAP endpoints with `curl` (Accept: application/rdap+json). Contact vCards of any
entity that is not the registrar were removed from the domain fixtures. The IP/ASN files keep only published role
contacts (abuse/NOC).

| File | Source | HTTP |
|---|---|---|
| verisign-com.json | https://rdap.verisign.com/com/v1/domain/madeinnewburgh.com | 200 |
| porkbun-referral.json | the `rel=related` link in verisign-com.json (Porkbun RDAP) | 200 |
| pir-org.json | https://rdap.publicinterestregistry.org/rdap/domain/wikipedia.org | 200 |
| centralnic-xyz.json | https://rdap.centralnic.com/xyz/domain/abc.xyz | 200 |
| not-found.txt | https://rdap.verisign.com/com/v1/domain/deed-fixture-nonexistent-7273.com (empty body) | 404 |
| ip-arin.json | https://rdap.arin.net/registry/ip/8.8.8.8 | 200 |
| autnum-arin.json | https://rdap.arin.net/registry/autnum/15169 | 200 |
| iana-dns.json | https://data.iana.org/rdap/dns.json, trimmed to services covering com, org, xyz, dev, uk | 200 |
| iana-ipv4.json | https://data.iana.org/rdap/ipv4.json, trimmed to services holding 8.x and 41.x prefixes | 200 |
| iana-ipv6.json | https://data.iana.org/rdap/ipv6.json, first two services | 200 |
| iana-asn.json | https://data.iana.org/rdap/asn.json, trimmed to services holding 1-… and 15360-16383 | 200 |
