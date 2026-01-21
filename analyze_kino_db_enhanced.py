#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script MEJORADO para analizar la base de datos SQL de KINO
Considera que los documentos tienen DOS nombres:
1. Nombre asignado manualmente en tabla (field: name)
2. Nombre del archivo PDF real (field: path)

Y que los códigos fueron asignados MANUALMENTE, así que puede haber errores.
"""

import re
from collections import defaultdict, Counter
from difflib import SequenceMatcher
from pathlib import Path

def parse_sql_file(sql_file):
    """Parse el archivo SQL y extrae datos de documentos y códigos"""
    
    with open(sql_file, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # Extraer documentos
    docs_pattern = r"INSERT INTO `documents` \(`id`, `name`, `date`, `path`, `codigos_extraidos`\) VALUES\s*((?:\([^;]+\)[,;]?\s*)+)"
    docs_match = re.search(docs_pattern, content, re.MULTILINE | re.DOTALL)
    
    documents = {}
    if docs_match:
        docs_data = docs_match.group(1)
        doc_pattern = r"\((\d+),\s*'([^']+)',\s*'([^']+)',\s*'([^']+)',\s*(NULL|'[^']*')\)"
        for match in re.finditer(doc_pattern, docs_data):
            doc_id = int(match.group(1))
            documents[doc_id] = {
                'id': doc_id,
                'name': match.group(2),
                'date': match.group(3),
                'path': match.group(4),
                'codigos_extraidos': match.group(5)
            }
    
    # Extraer códigos
    codes_pattern = r"INSERT INTO `codes` \(`id`, `document_id`, `code`\) VALUES\s*((?:\([^;]+\)[,;]?\s*)+)"
    codes_matches = re.findall(codes_pattern, content, re.MULTILINE | re.DOTALL)
    
    codes = []
    for codes_chunk in codes_matches:
        code_pattern = r"\((\d+),\s*(\d+),\s*'([^']+)'\)"
        for match in re.finditer(code_pattern, codes_chunk):
            codes.append({
                'id': int(match.group(1)),
                'document_id': int(match.group(2)),
                'code': match.group(3)
            })
    
    return documents, codes

def extract_pdf_name(path):
    """Extrae el nombre limpio del PDF desde el path"""
    # Remover el ID numérico al inicio (ej: "1748100868_")
    clean = re.sub(r'^\d+_', '', path)
    # Remover .pdf al final
    clean = clean.replace('.pdf', '')
    return clean.strip()

def similarity(a, b):
    """Calcula similitud entre dos strings (0-1)"""
    return SequenceMatcher(None, a.lower(), b.lower()).ratio()

def analyze_name_mismatches(documents, codes):
    """Analiza discrepancias entre nombre de tabla y nombre de archivo"""
    
    print("="*80)
    print("🔍 ANÁLISIS DE DISCREPANCIAS: NOMBRE TABLA vs NOMBRE ARCHIVO")
    print("="*80)
    print()
    
    mismatches = []
    
    for doc_id, doc in documents.items():
        table_name = doc['name']
        pdf_name = extract_pdf_name(doc['path'])
        
        # Calcular similitud
        sim = similarity(table_name, pdf_name)
        
        if sim < 0.7:  # Si la similitud es menor al 70%
            mismatches.append({
                'doc_id': doc_id,
                'table_name': table_name,
                'pdf_name': pdf_name,
                'similarity': sim,
                'path': doc['path']
            })
    
    if mismatches:
        print(f"⚠️  Encontrados {len(mismatches)} documentos con nombres muy diferentes:")
        print()
        
        for i, mm in enumerate(sorted(mismatches, key=lambda x: x['similarity'])[:20], 1):
            print(f"{i}. Doc ID {mm['doc_id']} (Similitud: {mm['similarity']:.0%})")
            print(f"   📋 Nombre en tabla: '{mm['table_name']}'")
            print(f"   📄 Nombre de PDF:   '{mm['pdf_name']}'")
            print(f"   🔗 Archivo: {mm['path']}")
            print()
        
        if len(mismatches) > 20:
            print(f"   ... y {len(mismatches) - 20} discrepancias más")
    else:
        print("✅ Todos los nombres coinciden razonablemente")
    
    print()
    return mismatches

def analyze_code_patterns(documents, codes):
    """Analiza patrones de códigos para detectar asignaciones sospechosas"""
    
    print("="*80)
    print("🔍 ANÁLISIS DE PATRONES DE CÓDIGOS POR DOCUMENTO")
    print("="*80)
    print()
    
    # Agrupar códigos por documento
    codes_by_doc = defaultdict(list)
    for code in codes:
        codes_by_doc[code['document_id']].append(code['code'])
    
    # Detectar códigos que parecen duplicados masivamente
    print("📊 CÓDIGOS CON MAYOR DUPLICACIÓN (pueden indicar error de asignación):")
    print()
    
    all_codes_with_docs = [(code['code'], code['document_id']) for code in codes]
    code_occurrences = defaultdict(list)
    for code, doc_id in all_codes_with_docs:
        code_occurrences[code].append(doc_id)
    
    # Ordenar por cantidad de documentos donde aparece
    most_duplicated = sorted(code_occurrences.items(), key=lambda x: len(x[1]), reverse=True)
    
    for i, (code, doc_ids) in enumerate(most_duplicated[:15], 1):
        if len(doc_ids) > 1:
            print(f"{i}. Código '{code}' aparece en {len(doc_ids)} documentos:")
            for doc_id in doc_ids[:5]:  # Mostrar solo primeros 5
                if doc_id in documents:
                    print(f"      • {documents[doc_id]['name']} (ID {doc_id})")
            if len(doc_ids) > 5:
                print(f"      ... y {len(doc_ids) - 5} documentos más")
            print()
    
    # Analizar documentos que comparten muchos códigos (posible duplicación de asignación)
    print("="*80)
    print("🔄 PARES DE DOCUMENTOS QUE COMPARTEN MUCHOS CÓDIGOS")
    print("(Puede indicar que los códigos se asignaron al documento equivocado)")
    print("="*80)
    print()
    
    doc_pairs_overlap = []
    doc_ids = list(codes_by_doc.keys())
    
    for i, doc_id_1 in enumerate(doc_ids):
        for doc_id_2 in doc_ids[i+1:]:
            codes_1 = set(codes_by_doc[doc_id_1])
            codes_2 = set(codes_by_doc[doc_id_2])
            
            overlap = codes_1 & codes_2
            overlap_pct = len(overlap) / min(len(codes_1), len(codes_2)) * 100
            
            if len(overlap) >= 10 and overlap_pct >= 5:  # Al menos 10 códigos en común y 5%
                doc_pairs_overlap.append({
                    'doc_id_1': doc_id_1,
                    'doc_id_2': doc_id_2,
                    'overlap_count': len(overlap),
                    'overlap_pct': overlap_pct,
                    'sample_codes': list(overlap)[:5]
                })
    
    doc_pairs_overlap.sort(key=lambda x: x['overlap_count'], reverse=True)
    
    if doc_pairs_overlap:
        for i, pair in enumerate(doc_pairs_overlap[:10], 1):
            doc1 = documents.get(pair['doc_id_1'])
            doc2 = documents.get(pair['doc_id_2'])
            
            if doc1 and doc2:
                print(f"{i}. {pair['overlap_count']} códigos compartidos ({pair['overlap_pct']:.1f}% del menor)")
                print(f"   📄 Doc 1: {doc1['name']} (ID {pair['doc_id_1']})")
                print(f"   📄 Doc 2: {doc2['name']} (ID {pair['doc_id_2']})")
                print(f"   🔢 Ejemplos: {', '.join(pair['sample_codes'])}")
                print()
        
        if len(doc_pairs_overlap) > 10:
            print(f"   ... y {len(doc_pairs_overlap) - 10} pares más con códigos compartidos")
    else:
        print("✅ No se encontraron pares de documentos con superposición significativa de códigos")
    
    print()
    return doc_pairs_overlap

def generate_summary_report(documents, codes, mismatches, duplicates_count):
    """Genera un reporte resumen"""
    
    print("="*80)
    print("📋 REPORTE RESUMEN - PROBLEMAS DETECTADOS")
    print("="*80)
    print()
    
    print(f"📊 ESTADÍSTICAS GENERALES:")
    print(f"   • Total documentos: {len(documents)}")
    print(f"   • Total códigos asignados: {len(codes)}")
    print(f"   • Promedio códigos/documento: {len(codes)/len(documents):.1f}")
    print()
    
    print(f"⚠️  PROBLEMAS IDENTIFICADOS:")
    print(f"   • {duplicates_count} códigos aparecen en múltiples documentos")
    print(f"   • {len(mismatches)} documentos con nombres muy diferentes (tabla vs archivo)")
    print()
    
    print(f"💡 RECOMENDACIONES:")
    print(f"   1. Revisar los códigos más duplicados - pueden estar asignados incorrectamente")
    print(f"   2. Verificar pares de documentos que comparten muchos códigos")
    print(f"   3. Corroborar que los nombres de tabla correspondan a los PDFs correctos")
    print(f"   4. Considerar validar los códigos contra el contenido real de los PDFs")
    print()

if __name__ == '__main__':
    sql_file = r'c:\Users\Usuario\Desktop\kino-trace\if0_39064130_buscador (10).sql'
    
    print("🔄 Cargando y parseando archivo SQL...")
    documents, codes = parse_sql_file(sql_file)
    
    print(f"✅ Cargados {len(documents)} documentos y {len(codes)} códigos")
    print()
    
    # Análisis 1: Discrepancias de nombres
    mismatches = analyze_name_mismatches(documents, codes)
    
    # Análisis 2: Patrones de códigos
    overlaps = analyze_code_patterns(documents, codes)
    
    # Contar duplicados
    code_occurrences = defaultdict(list)
    for code in codes:
        code_occurrences[code['code']].append(code['document_id'])
    duplicates = {code: docs for code, docs in code_occurrences.items() if len(docs) > 1}
    
    # Reporte final
    generate_summary_report(documents, codes, mismatches, len(duplicates))
    
    print("="*80)
    print("✅ ANÁLISIS COMPLETADO")
    print("="*80)
