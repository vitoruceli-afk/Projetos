// O LibreTranslate só tem português europeu (código "pt"). Este módulo troca,
// na saída, palavras e grafias típicas de Portugal pelas equivalentes do Brasil.
//
// É uma conversão aproximada, não uma tradução: cobre o vocabulário mais comum
// (informática, documentos, cotidiano) e não altera conjugações nem a colocação
// dos pronomes (ex.: "estou a fazer" continua assim). Para saída 100% pt-BR, use
// um provedor que aceite essa variante diretamente.

// Chave: forma europeia (minúsculas). Valor: forma brasileira.
const GLOSSARY = {
  // Informática
  // "ecrã" muda de gênero no Brasil (tela). Formas com artigo/preposição evitam "o tela".
  'o ecrã': 'a tela',
  'os ecrãs': 'as telas',
  'um ecrã': 'uma tela',
  'do ecrã': 'da tela',
  'no ecrã': 'na tela',
  'ao ecrã': 'à tela',
  'pelo ecrã': 'pela tela',
  ecrã: 'tela',
  ecrãs: 'telas',
  ficheiro: 'arquivo',
  ficheiros: 'arquivos',
  utilizador: 'usuário',
  utilizadores: 'usuários',
  utilizar: 'usar',
  actualizar: 'atualizar',
  actualização: 'atualização',
  actualizações: 'atualizações',
  // Vocabulário do dia a dia
  telemóvel: 'celular',
  telemóveis: 'celulares',
  equipa: 'equipe',
  equipas: 'equipes',
  contacto: 'contato',
  contactos: 'contatos',
  facto: 'fato',
  factos: 'fatos',
  autocarro: 'ônibus',
  autocarros: 'ônibus',
  comboio: 'trem',
  comboios: 'trens',
  frigorífico: 'geladeira',
  sumo: 'suco',
  chávena: 'xícara',
  pequeno_almoço: 'café da manhã',
  // Grafia (Acordo Ortográfico de 1990 já vigente no Brasil)
  registo: 'registro',
  registos: 'registros',
  objectivo: 'objetivo',
  objectivos: 'objetivos',
  director: 'diretor',
  directora: 'diretora',
  acção: 'ação',
  acções: 'ações',
  projecto: 'projeto',
  projectos: 'projetos',
  sector: 'setor',
  sectores: 'setores',
  óptimo: 'ótimo',
  óptima: 'ótima',
  optimizar: 'otimizar',
  receção: 'recepção',
  efectuar: 'efetuar',
  adoptar: 'adotar',
  espectáculo: 'espetáculo',
  espectáculos: 'espetáculos',
};

// Palavras com mais de uma palavra (ex.: "pequeno-almoço") são tratadas à parte, sem o "_".
const ENTRIES = Object.entries(GLOSSARY).map(([from, to]) => [from.replace(/_/g, '-'), to]);

const PATTERN = new RegExp(
  `(?<![\\p{L}\\p{N}])(${ENTRIES.map(([from]) => escapeRegExp(from)).sort((a, b) => b.length - a.length).join('|')})(?![\\p{L}\\p{N}])`,
  'giu',
);

const REPLACEMENTS = new Map(ENTRIES);

function escapeRegExp(text) {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Converte uma string de português europeu para uma aproximação do português brasileiro. */
export function toBrazilianPortuguese(text) {
  if (!text) return text;
  return text.replace(PATTERN, (match) => {
    const replacement = REPLACEMENTS.get(match.toLowerCase());
    if (!replacement) return match;
    // Preserva a capitalização: "Ecrã" vira "Tela", "ECRÃ" vira "TELA".
    if (match === match.toUpperCase() && match.length > 1) return replacement.toUpperCase();
    if (match[0] === match[0].toUpperCase()) return replacement[0].toUpperCase() + replacement.slice(1);
    return replacement;
  });
}
