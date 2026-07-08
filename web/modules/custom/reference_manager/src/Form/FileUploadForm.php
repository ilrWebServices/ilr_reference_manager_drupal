<?php

namespace Drupal\reference_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\node\NodeInterface;
use Drupal\reference_manager\PropertyValueAcademicIdentifier;

/**
 * Provides a file upload form for reference manager.
 */
class FileUploadForm extends FormBase {

  /**
   * Constructs a new FileUploadForm object.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected PasswordGeneratorInterface $passwordGenerator
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('password_generator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reference_manager_file_upload_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attributes']['enctype'] = 'multipart/form-data';

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Upload a reference file for processing. The file will be stored temporarily.') . '</p>',
    ];

    $form['file_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Choose file'),
      '#description' => $this->t('Upload a file. Allowed extension: xml.'),
      '#upload_location' => 'temporary://reference_manager/',
      '#upload_validators' => [
        'FileExtension' => [
          'extensions' => 'xml',
        ],
      ],
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload File'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $file_upload = $form_state->getValue('file_upload');

    if (empty($file_upload)) {
      $form_state->setErrorByName('file_upload', $this->t('Please select a file to upload.'));
      return;
    }

    // Load the file entity to validate it exists.
    $file = $this->entityTypeManager->getStorage('file')->load($file_upload[0]);
    if (!$file) {
      $form_state->setErrorByName('file_upload', $this->t('The uploaded file could not be found.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $file_upload = $form_state->getValue('file_upload');

    if (!empty($file_upload)) {
      /** @var \Drupal\file\Entity\File $file */
      $file = $this->entityTypeManager->getStorage('file')->load($file_upload[0]);

      if ($file) {
        // Set file to temporary status.
        $file->setTemporary();
        $file->save();

        $this->messenger()->addStatus($this->t('File "@filename" has been uploaded successfully to temporary storage.', [
          '@filename' => $file->getFilename(),
        ]));

        // Log the upload.
        $this->getLogger('reference_manager')->info('File uploaded: @filename (ID: @fid)', [
          '@filename' => $file->getFilename(),
          '@fid' => $file->id(),
        ]);

        $this->processUploadedFile($file);
      }
    }
  }

  /**
   * Process the uploaded file immediately.
   *
   * @param \Drupal\file\Entity\File $file
   *   The uploaded file entity.
   */
  protected function processUploadedFile(File $file) {
    try {
      $file_path = $file->getFileUri();
      $file_contents = file_get_contents($file_path);
      $user_storage = $this->entityTypeManager->getStorage('user');
      $node_storage = $this->entityTypeManager->getStorage('node');

      if ($file_contents !== FALSE) {
        // Example processing - you can customize this based on your needs.
        $file_size = strlen($file_contents);
        $line_count = substr_count($file_contents, "\n") + 1;

        $this->messenger->addStatus($this->t('File uploaded successfully. Size: @size bytes, Lines: @lines', [
          '@size' => number_format($file_size),
          '@lines' => $line_count,
        ]));

        $data = new \SimpleXMLElement($file_contents);

        foreach ($data->Survey as $user) {
          // Create a user if one doesn't exist.
          $account = $user_storage->loadByProperties([
            'name' => (string) $user['username'],
          ]);

          if ($account) {
            $account = reset($account);
          }
          else {
            $account = $user_storage->create([
              'name' => (string) $user['username'],
              'mail' => (string) $user['username'] . '@cornell.edu',
              // 'pass' => $this->passwordGenerator->generate(50),
              'status' => TRUE,
            ]);
            $account->save();
          }

          foreach ($user->INTELLCONT as $publication) {
            if ((string) $publication->PUBLIC_VIEW !== 'Yes') {
              continue;
            }

            if ((string) $publication->CONTYPE === 'Other') {
              continue;
            }

            // Create authors.
            $authors = [];
            foreach ($publication->INTELLCONT_AUTH as $author) {
              $new_author = $node_storage->create([
                'type' => 'refman_schema_person',
                'schema_given_name' => (string) $author->FNAME,
                'schema_family_name' => (string) $author->LNAME,
                'schema_additional_name' => (string) $author->MNAME,
                'uid' => $account->id(),
              ]);

              if ($new_author->save()) {
                $authors[] = $new_author;
              }
            }

            // Create a publisher, but don't set the name (title) or save it, yet.
            /** @var \Drupal\node\NodeInterface $publisher */
            $publisher = $node_storage->create([
              'type' => 'refman_schema_organization',
              'title' => '-',
              'uid' => $account->id(),
            ]);

            // Switch on the contribution type.
            if ((string) $publication->CONTYPE === 'Journal Article') {
              $entity_bundle = 'refman_schema_scholarly_article';
              $publisher->setTitle((string) $publication->JOURNAL->JOURNAL_NAME);
            }
            elseif ((string) $publication->CONTYPE === 'Book, Scholarly' || (string) $publication->CONTYPE === 'Book, Textbook') {
              $entity_bundle = 'refman_schema_book';
              $publisher->setTitle((string) $publication->PUBLISHER);
            }
            elseif ((string) $publication->CONTYPE === 'Book Chapter') {
              $entity_bundle = 'refman_schema_chapter';
              $publisher->setTitle((string) $publication->PUBLISHER);

            }
            elseif ((string) $publication->CONTYPE === 'Newspaper') {
              $entity_bundle = 'refman_schema_news_article';
              $publisher->setTitle((string) $publication->PUBLISHER);

            }
            elseif ((string) $publication->CONTYPE === 'Magazine Publication') {
              $entity_bundle = 'refman_schema_article';
              $publisher->setTitle((string) $publication->PUBLISHER);

            }
            else {
              // Plain old CreativeWork, which is the default.
              $entity_bundle = 'refman_schema_creative_work';
              $publisher->setTitle((string) $publication->PUBLISHER);
            }

            $publication_date = (string) $publication->PUB_START ?: (string) $publication->SUB_START;
            $publish_status = (string) $publication->STATUS === 'Published';

            /** @var \Drupal\node\NodeInterface $entity */
            $entity = $node_storage->create([
              'type' => $entity_bundle,
              'title' => (string) $publication->TITLE[0] ?? '-',
              'created' => strtotime($publication['created']),
              // 'changed' => strtotime($publication['lastModified']), // Why not work?
              'status' => $publish_status,
              'schema_date_published' => $publication_date,
              'uid' => $account->id(),
            ]);

            if ($entity->hasField('schema_pagination') && (string) $publication->PAGENUM) {
              $entity->set('schema_pagination', (string) $publication->PAGENUM);
            }

            // Mainly for simple creative works: Store the type from the import data.
            if ($entity->hasField('schema_additional_type')) {
              $entity->set('schema_additional_type', (string) $publication->CONTYPE);
            }

            if ($entity->hasField('schema_url') && (string) $publication->WEB_ADDRESS) {
              $entity->set('schema_url', ['uri' => (string) $publication->WEB_ADDRESS]);
            }

            // Create issue and volume.
            if ($entity->hasField('schema_is_part_of') && (string) $publication->VOLUME && (string) $publication->ISSUE) {
              /** @var \Drupal\node\NodeInterface $volume */
              $volume = $node_storage->create([
                'type' => 'refman_schema_publication_volume',
                'schema_volume_number' => (string) $publication->VOLUME,
                'uid' => $account->id(),
              ]);
              $volume->save();

              /** @var \Drupal\node\NodeInterface $issue */
              $issue = $node_storage->create([
                'type' => 'refman_schema_publication_issue',
                'schema_issue_number' => (string) $publication->ISSUE,
                'schema_is_part_of' => $volume,
                'uid' => $account->id(),
              ]);
              $issue->save();

              $entity->set('schema_is_part_of', $issue);
            }

            // Save and set the publisher.
            if ($publisher->label()) {
              $publisher->save();
              $entity->set('schema_publisher', $publisher);
            }

            // Set the authors.
            $entity->set('schema_author', $authors);

            // Set any academic identifiers. They are stored as a custom
            // schema.org PropertyValues content type
            if ($entity->hasField('schema_identifier_academic_id')) {
              /** @var PropertyValueAcademicIdentifier[] $academic_identifier_data */
              $academic_identifier_data = [];

              /** @var NodeInterface[] $academic_identifier_nodes */
              $academic_identifier_nodes = [];

              if ((string) $publication->ARXIVNUM) {
                $academic_identifier_data[] = new PropertyValueAcademicIdentifier('arXiv', (string) $publication->ARXIVNUM);
              }

              if ((string) $publication->DOI) {
                // See https://stackoverflow.com/a/48524047.
                preg_match('/10\.\d{4,9}\/[-._;()\/:A-Z0-9]+/', (string) $publication->DOI, $doi_matches);

                if ($doi_matches) {
                  $academic_identifier_data[] = new PropertyValueAcademicIdentifier('DOI', $doi_matches[0]);
                }
              }

              if ((string) $publication->PMID) {
                $academic_identifier_data[] = new PropertyValueAcademicIdentifier('PMID', (string) $publication->PMID);
              }

              if ((string) $publication->PMCID) {
                $academic_identifier_data[] = new PropertyValueAcademicIdentifier('PMCID', (string) $publication->PMCID);
              }

              foreach ($academic_identifier_data as $academic_identifier) {
                $new_academic_identifier = $node_storage->create([
                  'type' => 'refman_property_value_apid',
                  'schema_property_id_academic_id' => strtolower($academic_identifier->propertyId),
                  'schema_value_academic_id' => $academic_identifier->value,
                  'uid' => $account->id(),
                ]);

                if ($new_academic_identifier->save()) {
                  $academic_identifier_nodes[] = $new_academic_identifier;
                }
              }

              if ($academic_identifier_nodes) {
                $entity->set('schema_identifier_academic_id', $academic_identifier_nodes);
              }
            }

            // Save the entity.
            $entity->save();

          } // End publication
        } // End user



        // foreach ($ai_person_xml->Record->INTELLCONT as $publication) {
        //   // if ($published_only && (string) $publication->STATUS !== 'Published') {
        //   //   continue;
        //   // }

        //   // if ($public_only && (string) $publication->PUBLIC_VIEW !== 'Yes') {
        //   //   continue;
        //   // }

        //   if ((string) $publication->CONTYPE === 'Other') {
        //     continue;
        //   }


        //   $publication_group = match((string) $publication->CONTYPE) {
        //     'Journal Article' => 'Journal Articles',
        //     'Book, Scholarly' => 'Books',
        //     'Book Chapter' => 'Book Chapters',
        //     'Book, Textbook' => 'Textbooks',
        //     'Book Section' => 'Book Sections',
        //     'Newspaper' => 'Newspapers',
        //     'Instructor\'s Manual' => 'Instructor\'s Manuals',
        //     'Book Review' => 'Book Reviews',
        //     'Magazine Publication' => 'Magazine Publications',
        //     'Written Case' => 'Written Cases',
        //     'Cited Research' => 'Cited Research',
        //     'Conference Proceeding' => 'Conference Proceedings',
        //     'Abstract' => 'Abstracts',
        //     'Research Report' => 'Research Reports',
        //     'Research Bulletin' => 'Research Bulletins',
        //     default => (string) $publication->CONTYPE,
        //   };

        //   switch ($publication_group) {
        //     case 'Journal Articles':
        //       $doi = preg_replace('/https?:\/\/dx\.doi\.org\//', '', (string) $publication->DOI);

        //       $schemaObject = Schema::scholarlyArticle()
        //         ->publisher(Schema::organization()->name((string) $publication->JOURNAL->JOURNAL_NAME))
        //         ->pagination((string) $publication->PAGENUM)
        //         ->setProperty('x_arxivnum', (string) $publication->ARXIVNUM)
        //         ->setProperty('x_doi', $doi)
        //         ->setProperty('x_pmid', (string) $publication->PMID)
        //         ->setProperty('x_pmcid', (string) $publication->PMCID);

        //       if ((string) $publication->VOLUME) {
        //         $schemaObject->isPartOf(
        //           Schema::publicationIssue()
        //             ->issueNumber((string) $publication->ISSUE)
        //             ->isPartOf(
        //               Schema::publicationVolume()
        //                 ->volumeNumber((string) $publication->VOLUME)
        //             )
        //         );
        //       }
        //       break;

        //     case 'Books':
        //     case 'Textbooks':
        //       $schemaObject = Schema::book()
        //         ->publisher(Schema::organization()->name((string) $publication->PUBLISHER));
        //       break;

        //     case 'Book Chapters':
        //       $schemaObject = Schema::chapter()
        //         ->publisher(Schema::organization()->name((string) $publication->PUBLISHER))
        //         ->title((string) $publication->BOOK_TITLE)
        //         ->pagination((string) $publication->PAGENUM);
        //       break;

        //     case 'Newspapers':
        //       $schemaObject = Schema::newsArticle()
        //         ->publisher(Schema::organization()->name((string) $publication->PUBLISHER));
        //       break;

        //     case 'Book Reviews':
        //       $schemaObject = Schema::reviewNewsArticle();
        //       break;

        //     case 'Magazine Publications':
        //       $schemaObject = Schema::article()
        //         ->publisher(Schema::organization()->name((string) $publication->PUBLISHER))
        //         ->pagination((string) $publication->PAGENUM);

        //       if ((string) $publication->PAGENUM && (string) $publication->VOLUME) {
        //         $schemaObject->isPartOf(
        //           Schema::publicationIssue()
        //             ->issueNumber((string) $publication->ISSUE)
        //             ->isPartOf(
        //               Schema::publicationVolume()
        //                 ->volumeNumber((string) $publication->VOLUME)
        //             )
        //         );
        //       }
        //       break;

        //     default:
        //       $schemaObject = Schema::creativeWork();
        //   }

        //   $data[$publication_group][] = $schemaObject;

        //   $authors = [];
        //   foreach ($publication->INTELLCONT_AUTH as $author) {
        //     $authors[] = Schema::person()
        //       ->givenName((string) $author->FNAME)
        //       ->familyName((string) $author->LNAME)
        //       ->additionalName((string) $author->MNAME);
        //   }

        //   $publication_date = (string) $publication->PUB_START ?: (string) $publication->SUB_START;

        //   /** @var \Spatie\SchemaOrg\CreativeWork $schemaObject */
        //   $schemaObject
        //     ->name((string) $publication->TITLE[0])
        //     ->author($authors)
        //     ->datePublished(new \DateTime($publication_date))
        //     ->url((string) $publication->WEB_ADDRESS)
        //     ->setProperty('ai_contype', (string) $publication->CONTYPE)
        //     ->setProperty('ai_public_view', (string) $publication->PUBLIC_VIEW);
        // }

        // // Log the processing.
        // $this->loggerFactory->get('reference_manager')->info('File processed: @filename', [
        //   '@filename' => $file->getFilename(),
        // ]);
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error processing file: @error', [
        '@error' => $e->getMessage(),
      ]));

      $this->getLogger('reference_manager')->error('Error processing file @filename: @error', [
        '@filename' => $file->getFilename(),
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
