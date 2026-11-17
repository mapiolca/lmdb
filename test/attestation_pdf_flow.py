"""Check the --scope-pagebreak fixture using Poppler's actual PDF text positions."""
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

root = ET.fromstring(subprocess.check_output(["pdftotext", "-bbox", sys.argv[1], "-"]))
ns = {"x": "http://www.w3.org/1999/xhtml"}
pages = root.findall(".//x:page", ns)
texts = []
body = []
for page in pages:
    words = page.findall(".//x:word", ns)
    texts.append(" ".join(w.text or "" for w in words))
    # Native continuation header ends above 100pt; footer begins below 780pt.
    body.append(" ".join(w.text or "" for w in words if 100 <= float(w.attrib["yMin"]) and float(w.attrib["yMax"]) < 757))
    assert any("ATLF-TEST-0001" == w.text and float(w.attrib["yMax"]) < 100 for w in words), "Missing native header"
    assert any(float(w.attrib["yMin"]) > 780 for w in words), "Missing native footer"

assert len(pages) == 3, "Unexpected page count for the two-part fixture"
assert "DÉBUT PÉRIMÈTRE DE RECETTE." in texts[0], "Scope moved to next page, leaving the first page empty"
assert "FIN PÉRIMÈTRE DE RECETTE." in texts[1], "Scope did not continue on page 2"
assert "II. Volet 2" in texts[2], "Client part must start on its own page"
flow = " ".join(body)
scope = flow.split("DÉBUT PÉRIMÈTRE DE RECETTE.", 1)[1].split("FIN PÉRIMÈTRE DE RECETTE.", 1)[0]
expected = "Texte long avec accents : inaltérabilité, sécurisation, conservation et archivage. " * 40
assert re.sub(r"\s+", " ", scope).strip() == expected.strip(), "Scope text lost, repeated or drawn into the footer"
assert "retour DEUXIÈME LIGNE EXCLUE." in flow and "\\n" not in flow, "Escaped newline rendered in the PDF"
print("PASS Scope starts on page 1, continues on page 2, keeps every word and respects native headers/footers")
